<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RaceTiming;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RacingDepositRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * A sweep (or any other worker) moves the deposit while its send is in flight (ADR 0005, 0008): the answer meets a newer
 * version, reloads the deposit and settles it as it now is.
 */
final class DepositDpaeHandlerConcurrencyTest extends TestCase
{
    private static function command(): DepositDpae
    {
        return new DepositDpae(Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flow(DeclarationMode::Test, 'a'));
    }

    /**
     * What the sweep of the reconciliation does to a deposit that is still `depositing` too long.
     */
    private static function sweep(DepositRepository $repository, DepositId $id): void
    {
        $deposit = $repository->get($id);
        $deposit->markOutcomeUnknown('deposit.stale', Samples::at(Samples::NOW));
        $deposit->releaseEvents();
        $repository->save($deposit);
    }

    /**
     * The sweep, then the reconciliation: the deposit is settled by a flux before the answer comes back.
     */
    private static function sweepAndAdopt(DepositRepository $repository, DepositId $id): void
    {
        $deposit = $repository->get($id);
        $deposit->markOutcomeUnknown('deposit.stale', Samples::at(Samples::NOW));
        $deposit->adoptAsReceived(Samples::flowId('F9'), Samples::at(Samples::NOW));
        $deposit->releaseEvents();
        $repository->save($deposit);
    }

    private static function touch(DepositRepository $repository, DepositId $id): void
    {
        $repository->save($repository->get($id));
    }

    public function testAnAnswerThatComesAfterTheSweepSettlesTheUnknownOutcome(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $racing = new RacingDepositRepository($harness->deposits, self::sweep(...), RaceTiming::AfterClaim);

        $deposit = $harness->depositHandlerOver($racing, DeclarationMode::Test)(self::command());

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertNotNull($deposit->result());
        static::assertSame(DepositState::Received, $harness->deposits->get($deposit->id())->state());
        static::assertSame(1, array_count_values($harness->journal->entries())['rollback']);
        static::assertCount(1, $harness->events->events());
        static::assertInstanceOf(DepositReceived::class, $harness->events->events()[0]);
        static::assertCount(1, $harness->outbox->events());
    }

    public function testAnAnswerThatComesAfterTheDepositWasSettledByAnotherWorkerChangesNothing(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $racing = new RacingDepositRepository($harness->deposits, self::sweepAndAdopt(...), RaceTiming::AfterClaim);

        $deposit = $harness->depositHandlerOver($racing, DeclarationMode::Test)(self::command());

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F9', $deposit->flowId()?->value());
        static::assertTrue($deposit->adoptedByReconciliation());
        static::assertNull($deposit->result());
        static::assertSame(3, $deposit->version());
        static::assertSame([], $harness->events->events());
    }

    public function testAFailureThatComesAfterTheSweepLeavesTheOutcomeUnknownAndIsRethrown(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $failure = UrssafUnavailable::whileAuthenticating(Operation::Deposit, 503, null, null);
        $harness->gateway->willDeposit($failure);
        $racing = new RacingDepositRepository($harness->deposits, self::sweep(...), RaceTiming::AfterClaim);

        $thrown = Outcome::of(static fn(): Deposit => $harness->depositHandlerOver($racing, DeclarationMode::Test)(self::command()));

        static::assertSame($failure, $thrown);
        $deposit = $harness->deposits->get(DepositId::fromString('id-1'));
        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertSame('deposit.stale', $deposit->lastErrorCode());
        static::assertSame(3, $deposit->version());
    }

    public function testADepositThatKeepsChangingUnderTheAnswerEndsInAConflict(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $racing = new RacingDepositRepository($harness->deposits, self::touch(...), RaceTiming::EveryTimeAfterClaim);

        $thrown = Outcome::of(static fn(): Deposit => $harness->depositHandlerOver($racing, DeclarationMode::Test)(self::command()));

        static::assertInstanceOf(ConcurrentModification::class, $thrown);
        static::assertSame(3, array_count_values($harness->journal->entries())['rollback']);
        static::assertSame(DepositState::Depositing, $harness->deposits->get(DepositId::fromString('id-1'))->state());
        static::assertSame([], $harness->events->events());
    }
}
