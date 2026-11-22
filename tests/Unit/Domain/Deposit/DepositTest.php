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
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DepositTest extends TestCase
{
    use DepositFixtures;

    public function testARequestedDepositIsPendingAndOpen(): void
    {
        $deposit = self::deposit();

        static::assertSame('d1', $deposit->id()->value());
        static::assertSame('tenant-1', $deposit->tenantId()->value());
        static::assertSame('ref-1', $deposit->externalReference()->value());
        static::assertTrue($deposit->fingerprint()->equals(Samples::fingerprint('a')));
        static::assertSame(DeclarationMode::Test, $deposit->mode());
        static::assertInstanceOf(Siret::class, $deposit->declarantSiret());
        static::assertSame(DepositState::Pending, $deposit->state());
        static::assertSame(0, $deposit->attempts());
        static::assertSame(self::T0, $deposit->claimedAt()->toIso8601());
        static::assertSame(self::T0, $deposit->updatedAt()->toIso8601());
        static::assertNull($deposit->flowId());
        static::assertNull($deposit->result());
        static::assertNull($deposit->lastErrorCode());
        static::assertTrue($deposit->isOpen());
        static::assertSame([], $deposit->releaseEvents());
    }

    public function testClaimingCountsTheAttemptAndRecordsTheInstant(): void
    {
        $deposit = self::depositing();

        static::assertSame(DepositState::Depositing, $deposit->state());
        static::assertSame(1, $deposit->attempts());
        static::assertSame(self::T1, $deposit->claimedAt()->toIso8601());
        static::assertSame(self::T1, $deposit->updatedAt()->toIso8601());
    }

    public function testAReceiptRecordsTheFlowIdAndAReceivedEvent(): void
    {
        $deposit = self::depositing();
        $receipt = SampleAnswers::receipt('F1');

        $deposit->recordResult($receipt, Samples::at(self::T2));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame($receipt, $deposit->result());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertSame(self::T2, $deposit->updatedAt()->toIso8601());
        static::assertSame(self::T1, $deposit->claimedAt()->toIso8601());
        $events = $deposit->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(DepositReceived::class, $events[0]);
        static::assertSame('d1', $events[0]->depositId->value());
        static::assertSame('F1', $events[0]->flowId?->value());
        static::assertSame(self::T2, $events[0]->occurredAt()->toIso8601());
        static::assertSame([], $deposit->releaseEvents());
    }

    public function testARejectionNoticeClosesTheDepositWithARejectedEvent(): void
    {
        $deposit = self::depositing();

        $deposit->recordResult(SampleAnswers::rejection('F2'), Samples::at(self::T2));

        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertFalse($deposit->isOpen());
        static::assertSame('F2', $deposit->flowId()?->value());
        $events = $deposit->releaseEvents();
        static::assertInstanceOf(DepositRejected::class, $events[0]);
        static::assertSame(self::T2, $events[0]->occurredAt()->toIso8601());
    }

    public function testAReceiptWithoutFlowIdLeavesItUnknown(): void
    {
        $deposit = self::depositing();

        $deposit->recordResult(SampleAnswers::receiptWithoutFlowId(), Samples::at(self::T2));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertNull($deposit->flowId());
    }

    public function testAResultOnlyFollowsAClaim(): void
    {
        $this->expectException(InvalidStateTransition::class);

        self::deposit()->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T2));
    }

    public function testEveryRecordedEventIsReleasedOnceInOrder(): void
    {
        $deposit = self::depositing();
        $deposit->markOutcomeUnknown('x', Samples::at(self::T2));
        $deposit->resolveAsReceived(Samples::flowId('F3'), Samples::at(self::T2));

        $events = $deposit->releaseEvents();

        static::assertCount(2, $events);
        static::assertInstanceOf(DepositOutcomeUnknown::class, $events[0]);
        static::assertInstanceOf(DepositReceived::class, $events[1]);
        static::assertSame([], $deposit->releaseEvents());
    }

    public function testTheVersionOnlyMovesWhenARepositoryAdvancesIt(): void
    {
        $deposit = self::depositing();

        static::assertSame(0, $deposit->version());
        $deposit->advanceVersion();
        $deposit->markOutcomeUnknown('x', Samples::at('2022-02-01T10:02:00Z'));
        static::assertSame(1, $deposit->version());
    }
}
