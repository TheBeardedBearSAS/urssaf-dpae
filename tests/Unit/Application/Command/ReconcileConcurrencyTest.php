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
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RaceTiming;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RacingDepositRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * Another worker moves a deposit between the moment the reconciliation reads it and the moment it saves it (ADR 0005,
 * 0008): the save meets a newer version, the deposit is reloaded and the reconciliation decides again.
 */
final class ReconcileConcurrencyTest extends TestCase
{
    use ReconciliationFixtures;

    private static function answer(DepositRepository $repository, DepositId $id, string $flowId): void
    {
        $answered = $repository->get($id);
        $answered->recordResult(SampleAnswers::receipt($flowId), Samples::at('2022-02-01T11:59:00Z'));
        $answered->releaseEvents();
        $repository->save($answered);
    }

    private static function answerF1(DepositRepository $repository, DepositId $id): void
    {
        self::answer($repository, $id, 'F1');
    }

    private static function answerF2(DepositRepository $repository, DepositId $id): void
    {
        self::answer($repository, $id, 'F2');
    }

    private static function notReceived(DepositRepository $repository, DepositId $id): void
    {
        $resolved = $repository->get($id);
        $resolved->resolveAsNotReceived(Samples::at('2022-02-01T11:59:00Z'));
        $repository->save($resolved);
    }

    private static function touch(DepositRepository $repository, DepositId $id): void
    {
        $repository->save($repository->get($id));
    }

    public function testAnAnswerSavedDuringTheSweepIsNotOverwrittenByIt(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T10:00:00Z');
        $harness->gateway->willList([]);
        $racing = new RacingDepositRepository($harness->deposits, self::answerF1(...), RaceTiming::NextSave);

        $report = $harness->reconcileHandlerOver($racing)(self::command());

        static::assertSame([], $report->markedUnknown);
        $stored = $harness->deposits->get($deposit->id());
        static::assertSame(DepositState::Received, $stored->state());
        static::assertSame('F1', $stored->flowId()?->value());
        static::assertSame([], $harness->outbox->events());
    }

    public function testASweepStillHappensWhenTheDepositOnlyChangedItsVersion(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T10:00:00Z');
        $harness->gateway->willList([]);
        $racing = new RacingDepositRepository($harness->deposits, self::touch(...), RaceTiming::NextSave);

        $report = $harness->reconcileHandlerOver($racing)(self::command());

        static::assertSame([$deposit->id()], $report->markedUnknown);
        static::assertSame(DepositState::OutcomeUnknown, $harness->deposits->get($deposit->id())->state());
    }

    public function testAnAnswerThatArrivesBeforeTheAdoptionKeepsItsOwnFlowId(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);
        $racing = new RacingDepositRepository($harness->deposits, self::answerF2(...), RaceTiming::NextSave);

        $report = $harness->reconcileHandlerOver($racing)(self::command());

        static::assertSame([], $report->adopted);
        $stored = $harness->deposits->get($deposit->id());
        static::assertSame('F2', $stored->flowId()?->value());
        static::assertFalse($stored->adoptedByReconciliation());
    }

    public function testADepositAnOperatorClosedBeforeTheAdoptionIsLeftAlone(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);
        $racing = new RacingDepositRepository($harness->deposits, self::notReceived(...), RaceTiming::NextSave);

        $report = $harness->reconcileHandlerOver($racing)(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::NotReceived, $harness->deposits->get($deposit->id())->state());
    }

    public function testAnAdoptionIsRetriedOnTheReloadedDeposit(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);
        $racing = new RacingDepositRepository($harness->deposits, self::touch(...), RaceTiming::NextSave);

        $report = $harness->reconcileHandlerOver($racing)(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        $stored = $harness->deposits->get($deposit->id());
        static::assertSame(DepositState::Received, $stored->state());
        static::assertTrue($stored->adoptedByReconciliation());
    }
}
