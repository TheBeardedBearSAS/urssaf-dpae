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
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived as PublicReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ReconcileAdoptionTest extends TestCase
{
    use ReconciliationFixtures;

    public function testASoleCandidateInTheWindowSettlesAnUnknownDepositAsReceived(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame([], $report->needingReview);
        static::assertSame([], $report->markedUnknown);
        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertSame(Samples::NOW, $deposit->updatedAt()->toIso8601());
        static::assertSame(self::CLAIMED, $deposit->claimedAt()->toIso8601());
        static::assertCount(1, $harness->gateway->listCalls());
        static::assertSame('tenant-1', $harness->gateway->listCalls()[0][0]->value());
        static::assertSame('2022-02-01T10:25:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame('2022-02-01T11:30:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
        $types = array_map(static fn(IntegrationEvent $event): string => $event::class, $harness->outbox->events());
        static::assertSame([PublicReceived::class, PublicAdopted::class, ReturnAvailable::class], $types);
        $tracked = $harness->returns->find(Samples::tenant('tenant-1'), self::nature10('F1', 'OK', null)->identity());
        static::assertSame($deposit->id()->value(), $tracked?->depositId()?->value());
    }

    public function testAKoStatusSettlesItAsRejected(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'KO', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
    }

    #[DataProvider('unsettlingStatuses')]
    public function testAnyOtherStatusLeavesTheUnknownDepositUnknownAndWithoutFlowId(string $status): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', $status, '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame([], $report->needingReview);
        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertNull($deposit->flowId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsettlingStatuses(): iterable
    {
        yield 'in progress' => ['EN_COURS'];
        yield 'anomaly' => ['ANO'];
        yield 'unknown code' => ['ZZZ'];
    }

    #[DataProvider('windowEdges')]
    public function testTheWindowBoundsAreIncluded(string $publishedAt, DepositState $expected): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', $publishedAt)]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame($expected, $deposit->state());
        static::assertSame($expected === DepositState::Received ? [$deposit->id()] : [], $report->adopted);
    }

    /**
     * @return iterable<string, array{string, DepositState}>
     */
    public static function windowEdges(): iterable
    {
        yield 'just before the start' => ['2022-02-01T10:24:59Z', DepositState::OutcomeUnknown];
        yield 'at the start' => ['2022-02-01T10:25:00Z', DepositState::Received];
        yield 'at the claim' => ['2022-02-01T10:30:00Z', DepositState::Received];
        yield 'at the end' => ['2022-02-01T11:30:00Z', DepositState::Received];
        yield 'just after the end' => ['2022-02-01T11:30:01Z', DepositState::OutcomeUnknown];
    }

    public function testTwoCandidatesInTheWindowAreNeverGuessedBetween(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z'), self::nature10('F2', 'OK', '2022-02-01T10:50:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertNull($deposit->flowId());
    }
}
