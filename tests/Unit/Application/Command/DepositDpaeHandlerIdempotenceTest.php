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

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\LosingClaimDepositRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use Throwable;

final class DepositDpaeHandlerIdempotenceTest extends TestCase
{
    use DepositHandlerFixtures;

    public function testTheSameReferenceAndFlowReturnTheExistingDepositWithoutSendingAgain(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $handler = $harness->depositHandler(DeclarationMode::Test);

        $first = $handler(self::command('a', 'ref-1', 'tenant-1'));
        $second = $handler(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame($first, $second);
        static::assertCount(1, $harness->gateway->depositedFor());
    }

    public function testTheSameReferenceWithAnotherFlowIsAConflict(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $handler = $harness->depositHandler(DeclarationMode::Test);
        $handler(self::command('a', 'ref-1', 'tenant-1'));

        try {
            $handler(self::command('b', 'ref-1', 'tenant-1'));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('deposit.reference_conflict', $e->violationCode());
        }
        static::assertCount(1, $harness->gateway->depositedFor());
    }

    public function testTheSameFlowUnderAnotherReferenceIsRefused(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $handler = $harness->depositHandler(DeclarationMode::Test);
        $handler(self::command('a', 'ref-1', 'tenant-1'));

        try {
            $handler(self::command('a', 'ref-2', 'tenant-1'));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('deposit.flow_already_open', $e->violationCode());
        }
        static::assertNull($harness->deposits->findOpenByReference(Samples::tenant('tenant-1'), Samples::reference('ref-2')));
    }

    public function testTheSameReferenceAndFlowOfAnotherTenantAreIndependent(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::receipt('F1'));
        $harness->gateway->willDeposit(SampleAnswers::receipt('F2'));
        $handler = $harness->depositHandler(DeclarationMode::Test);

        $first = $handler(self::command('a', 'ref-1', 'tenant-1'));
        $second = $handler(self::command('a', 'ref-1', 'tenant-c'));

        static::assertNotSame($first, $second);
        static::assertCount(2, $harness->gateway->depositedFor());
    }

    public function testARejectedDepositMayBeDepositedAgainUnderTheSameReference(): void
    {
        $harness = self::harness();
        $harness->gateway->willDeposit(SampleAnswers::rejection('F1'));
        $harness->gateway->willDeposit(SampleAnswers::receipt('F2'));
        $handler = $harness->depositHandler(DeclarationMode::Test);

        $first = $handler(self::command('a', 'ref-1', 'tenant-1'));
        $second = $handler(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame(DepositState::Rejected, $first->state());
        static::assertSame(DepositState::Received, $second->state());
        static::assertNotSame($first->id()->value(), $second->id()->value());
        static::assertCount(2, $harness->gateway->depositedFor());
    }

    public function testAWorkerThatLosesTheClaimNeverCallsTheGateway(): void
    {
        $harness = self::harness();
        $handler = $harness->depositHandlerOver(new LosingClaimDepositRepository($harness->deposits), DeclarationMode::Test);

        $deposit = $handler(self::command('a', 'ref-1', 'tenant-1'));

        static::assertSame([], $harness->gateway->depositedFor());
        static::assertSame(DepositState::Depositing, $deposit->state());
        static::assertSame($harness->deposits->get($deposit->id()), $deposit);
    }

    public function testAnUnknownTenantRecordsNothing(): void
    {
        $harness = self::harness();

        try {
            $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'nobody'));
            static::fail('RecordNotFound expected.');
        } catch (RecordNotFound $e) {
            static::assertSame('credentials.not_found', $e->violationCode());
        }

        static::assertNull($harness->deposits->findOpenByReference(Samples::tenant('nobody'), Samples::reference('ref-1')));
        static::assertSame([], $harness->gateway->depositedFor());
    }

    #[DataProvider('settlements')]
    public function testEveryOutcomeIsSavedWhenTheRepositoryKeepsCopies(DepositResult|Throwable $answer, DepositState $expected): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $harness->gateway->willDeposit($answer);

        Outcome::of(static fn(): Deposit => $harness->depositHandler(DeclarationMode::Test)(self::command('a', 'ref-1', 'tenant-1')));

        $saved = $harness->deposits->get(DepositId::fromString('id-1'));
        static::assertSame($expected, $saved->state());
        static::assertSame(1, $saved->attempts());
    }

    /**
     * @return iterable<string, array{DepositResult|Throwable, DepositState}>
     */
    public static function settlements(): iterable
    {
        yield 'receipt' => [SampleAnswers::receipt('F1'), DepositState::Received];
        yield 'rejection' => [SampleAnswers::rejection('F1'), DepositState::Rejected];
        yield 'unknown outcome' => [OutcomeUnknown::afterStatus(Operation::Deposit, 503), DepositState::OutcomeUnknown];
        yield 'credentials' => [UrssafAuthenticationFailed::because(AuthenticationFailure::InvalidCredentials, 'realm'), DepositState::InvalidCredentials];
        yield 'not processed' => [UrssafRequestRejected::because(Operation::Deposit, 400, 'deposit.bad_request'), DepositState::Failed];
        yield 'not sent' => [PollingConstraintViolated::withStatus(429, null), DepositState::Pending];
        yield 'bug' => [new Exception('bug'), DepositState::OutcomeUnknown];
    }
}
