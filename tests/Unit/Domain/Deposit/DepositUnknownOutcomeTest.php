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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DepositUnknownOutcomeTest extends TestCase
{
    use DepositFixtures;

    public function testAnUnknownOutcomeIsRecordedWithItsEvent(): void
    {
        $deposit = self::depositing();

        $deposit->markOutcomeUnknown('deposit.outcome_unknown', Samples::at(self::T2));

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertTrue($deposit->isOpen());
        static::assertSame('deposit.outcome_unknown', $deposit->lastErrorCode());
        $events = $deposit->releaseEvents();
        static::assertInstanceOf(DepositOutcomeUnknown::class, $events[0]);
        static::assertSame('deposit.outcome_unknown', $events[0]->errorCode);
        static::assertSame(self::T2, $events[0]->occurredAt()->toIso8601());
    }

    public function testAnUnknownOutcomeCanBeResolvedAsReceived(): void
    {
        $deposit = self::depositing();
        $deposit->markOutcomeUnknown('x', Samples::at(self::T2));
        $deposit->releaseEvents();

        $deposit->resolveAsReceived(Samples::flowId('F3'), Samples::at(self::T2));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F3', $deposit->flowId()?->value());
        static::assertInstanceOf(DepositReceived::class, $deposit->releaseEvents()[0]);
    }

    public function testAnUnknownOutcomeCanBeResolvedAsRejected(): void
    {
        $deposit = self::depositing();
        $deposit->markOutcomeUnknown('x', Samples::at(self::T2));
        $deposit->releaseEvents();

        $deposit->resolveAsRejected(Samples::flowId('F4'), Samples::at(self::T2));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertSame('F4', $deposit->flowId()?->value());
        static::assertInstanceOf(DepositRejected::class, $deposit->releaseEvents()[0]);
    }

    public function testAnUnknownOutcomeCanBeResolvedAsNotReceived(): void
    {
        $deposit = self::depositing();
        $deposit->markOutcomeUnknown('x', Samples::at(self::T2));
        $deposit->releaseEvents();

        $deposit->resolveAsNotReceived(Samples::at(self::T2));

        static::assertSame(DepositState::NotReceived, $deposit->state());
        static::assertFalse($deposit->isOpen());
        static::assertNull($deposit->flowId());
        static::assertSame([], $deposit->releaseEvents());
    }

    public function testAReceiptWithoutFlowIdGainsItByReconciliation(): void
    {
        $deposit = self::depositing();
        $deposit->recordResult(SampleAnswers::receiptWithoutFlowId(), Samples::at(self::T2));

        $deposit->gainFlowId(Samples::flowId('F5'), Samples::at('2022-02-01T10:03:00Z'));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F5', $deposit->flowId()?->value());
        static::assertSame('2022-02-01T10:03:00Z', $deposit->updatedAt()->toIso8601());
    }

    public function testARejectedDepositWithoutFlowIdAlsoGainsIt(): void
    {
        $deposit = self::depositing();
        $deposit->recordResult(SampleAnswers::rejectionWithoutFlowId(), Samples::at(self::T2));

        $deposit->gainFlowId(Samples::flowId('F6'), Samples::at(self::T2));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertSame('F6', $deposit->flowId()?->value());
    }

    public function testAKnownFlowIdIsNeverReplaced(): void
    {
        $deposit = self::depositing();
        $deposit->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T2));

        try {
            $deposit->gainFlowId(Samples::flowId('F9'), Samples::at(self::T2));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('deposit.flow_id_already_known', $e->violationCode());
        }
        static::assertSame('F1', $deposit->flowId()?->value());
    }

    public function testAFlowIdOnlyJoinsAnAnsweredDeposit(): void
    {
        $this->expectException(InvalidStateTransition::class);

        self::depositing()->gainFlowId(Samples::flowId('F5'), Samples::at(self::T2));
    }
}
