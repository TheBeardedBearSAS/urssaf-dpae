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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositAdopted as PublicAdopted;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositOutcomeUnknown as PublicOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived as PublicReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;

final class ReconcileSweepTest extends TestCase
{
    use ReconciliationFixtures;

    public function testEverySweptDepositIsReportedAndAnnounced(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $first = self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T05:00:00Z');
        $second = self::sent($harness, 'd2', 'tenant-1', 'b', '2022-02-01T05:30:00Z');
        $harness->gateway->willList([]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$first->id(), $second->id()], $report->markedUnknown);
        $types = array_map(static fn(IntegrationEvent $event): string => $event::class, $harness->outbox->events());
        static::assertSame([PublicOutcomeUnknown::class, PublicOutcomeUnknown::class], $types);
        static::assertSame([$first->id()->value(), $second->id()->value()], array_map(static fn(IntegrationEvent $event): string => $event->aggregateId, $harness->outbox->events()));
    }

    #[DataProvider('claimAges')]
    public function testADepositLeftDepositingLongerThanTheStaleDelayBecomesAnUnknownOutcome(string $claimedAt, DepositState $expected): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', $claimedAt);
        $harness->gateway->willList([]);

        $report = $harness->reconcileHandler()(self::command());

        $stale = $expected === DepositState::OutcomeUnknown;
        static::assertSame($expected, $deposit->state());
        static::assertSame($stale ? [$deposit->id()] : [], $report->markedUnknown);
        static::assertSame($stale ? 'deposit.stale' : null, $deposit->lastErrorCode());
        static::assertCount($stale ? 1 : 0, $harness->gateway->listCalls());
    }

    /**
     * @return iterable<string, array{string, DepositState}>
     */
    public static function claimAges(): iterable
    {
        yield 'one second past the delay' => ['2022-02-01T11:44:59Z', DepositState::OutcomeUnknown];
        yield 'exactly at the delay' => ['2022-02-01T11:45:00Z', DepositState::Depositing];
        yield 'recent' => ['2022-02-01T11:59:00Z', DepositState::Depositing];
    }

    public function testAStaleDepositIsReconciledInTheSameRunAndAnnounced(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->markedUnknown);
        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame(DepositState::Received, $deposit->state());
        $types = array_map(static fn(IntegrationEvent $event): string => $event::class, $harness->outbox->events());
        static::assertSame([PublicOutcomeUnknown::class, PublicReceived::class, PublicAdopted::class, ReturnAvailable::class], $types);
        $first = $harness->outbox->events()[0];
        static::assertInstanceOf(PublicOutcomeUnknown::class, $first);
        static::assertSame('deposit.stale', $first->errorCode);
    }
}
