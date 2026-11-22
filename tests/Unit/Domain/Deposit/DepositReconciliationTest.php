<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Deposit;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositAdopted;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositFailed;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositParked;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * What the reconciliation, the late answers, the parking and the version add to the lifecycle of a deposit (ADR 0005).
 */
final class DepositReconciliationTest extends TestCase
{
    private const T0 = '2022-02-01T10:00:00Z';
    private const T1 = '2022-02-01T10:01:00Z';
    private const T2 = '2022-02-01T10:02:00Z';

    private static function depositing(): Deposit
    {
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T0);
        $deposit->startDeposit(Samples::at(self::T1), 3);

        return $deposit;
    }

    private static function unknown(): Deposit
    {
        $deposit = self::depositing();
        $deposit->markOutcomeUnknown('deposit.outcome_unknown', Samples::at(self::T1));
        $deposit->releaseEvents();

        return $deposit;
    }

    public function testAnUnknownOutcomeAdoptedAsReceivedIsMarkedAndAnnounced(): void
    {
        $deposit = self::unknown();

        $deposit->adoptAsReceived(Samples::flowId('F1'), Samples::at(self::T2));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertTrue($deposit->adoptedByReconciliation());
        static::assertSame('F1', $deposit->flowId()?->value());
        $events = $deposit->releaseEvents();
        static::assertCount(2, $events);
        static::assertInstanceOf(DepositReceived::class, $events[0]);
        static::assertInstanceOf(DepositAdopted::class, $events[1]);
        static::assertSame(DepositState::Received, $events[1]->state);
        static::assertSame('F1', $events[1]->flowId->value());
        static::assertSame(self::T2, $events[1]->occurredAt()->toIso8601());
    }

    public function testAnUnknownOutcomeAdoptedAsRejectedIsMarkedAndAnnounced(): void
    {
        $deposit = self::unknown();

        $deposit->adoptAsRejected(Samples::flowId('F1'), Samples::at(self::T2));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertTrue($deposit->adoptedByReconciliation());
        $events = $deposit->releaseEvents();
        static::assertInstanceOf(DepositRejected::class, $events[0]);
        static::assertInstanceOf(DepositAdopted::class, $events[1]);
        static::assertSame(DepositState::Rejected, $events[1]->state);
    }

    public function testAFlowIdFoundByReconciliationIsAnAdoptionToo(): void
    {
        $deposit = self::depositing();
        $deposit->recordResult(SampleAnswers::receiptWithoutFlowId(), Samples::at(self::T1));
        $deposit->releaseEvents();

        $deposit->gainFlowId(Samples::flowId('F1'), Samples::at(self::T2));

        static::assertTrue($deposit->adoptedByReconciliation());
        $events = $deposit->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(DepositAdopted::class, $events[0]);
        static::assertSame(DepositState::Received, $events[0]->state);
    }

    public function testAnOperatorResolutionOrAnAnswerIsNotAnAdoption(): void
    {
        $resolved = self::unknown();
        $resolved->resolveAsReceived(Samples::flowId('F1'), Samples::at(self::T2));
        $answered = self::depositing();
        $answered->recordResult(SampleAnswers::receipt('F2'), Samples::at(self::T2));

        static::assertFalse($resolved->adoptedByReconciliation());
        static::assertFalse($answered->adoptedByReconciliation());
        static::assertFalse(self::depositing()->adoptedByReconciliation());
    }

    public function testALateAnswerSettlesAnUnknownOutcome(): void
    {
        $deposit = self::unknown();

        $deposit->recordResult(SampleAnswers::rejection('F1'), Samples::at(self::T2));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertNotNull($deposit->result());
        static::assertFalse($deposit->adoptedByReconciliation());
        static::assertInstanceOf(DepositRejected::class, $deposit->releaseEvents()[0]);
    }

    public function testTheClaimRefusesADepositWithoutAttemptLeft(): void
    {
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T0);

        $failure = Outcome::of(static fn() => $deposit->startDeposit(Samples::at(self::T1), 0));

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame('deposit.attempts_exhausted', $failure->violationCode());
        static::assertSame(['attempts' => 0, 'max_attempts' => 0], $failure->params());
        static::assertSame(DepositState::Pending, $deposit->state());
        static::assertSame(0, $deposit->attempts());
    }

    public function testRequeuingGivesTheDepositAFreshAttemptBudget(): void
    {
        $deposit = self::depositing();
        $deposit->markInvalidCredentials(Samples::at(self::T1));
        static::assertSame(1, $deposit->attempts());

        $deposit->requeue(Samples::at(self::T2));
        $deposit->startDeposit(Samples::at(self::T2), 1);

        static::assertSame(1, $deposit->attempts());
        static::assertSame(DepositState::Depositing, $deposit->state());
    }

    public function testAClosedAndAParkedDepositAreAnnounced(): void
    {
        $parked = self::depositing();
        $parked->markInvalidCredentials(Samples::at(self::T2));
        $refused = self::depositing();
        $refused->markFailed('deposit.not_processed', Samples::at(self::T2));
        $exhausted = self::depositing();
        $exhausted->markNotSent('deposit.not_sent', 1, Samples::at(self::T2));
        $retried = self::depositing();
        $retried->markNotSent('deposit.not_sent', 2, Samples::at(self::T2));

        $parkedEvents = $parked->releaseEvents();
        static::assertInstanceOf(DepositParked::class, $parkedEvents[0]);
        static::assertSame('deposit.invalid_credentials', $parkedEvents[0]->errorCode);
        static::assertSame(self::T2, $parkedEvents[0]->occurredAt()->toIso8601());
        $refusedEvents = $refused->releaseEvents();
        static::assertInstanceOf(DepositFailed::class, $refusedEvents[0]);
        static::assertSame('deposit.not_processed', $refusedEvents[0]->errorCode);
        static::assertSame(self::T2, $refusedEvents[0]->occurredAt()->toIso8601());
        $exhaustedEvents = $exhausted->releaseEvents();
        static::assertInstanceOf(DepositFailed::class, $exhaustedEvents[0]);
        static::assertSame('deposit.not_sent', $exhaustedEvents[0]->errorCode);
        static::assertSame([], $retried->releaseEvents());
    }
}
