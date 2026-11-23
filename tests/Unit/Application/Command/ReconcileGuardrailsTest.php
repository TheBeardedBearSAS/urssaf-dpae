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

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Dto\ManualReviewItem;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositAdopted as PublicAdopted;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Policies;

/**
 * What keeps the reconciliation from guessing (ADR 0005): a closed window, the policy, the retention of URSSAF, the range
 * of a listing and a refused listing.
 */
final class ReconcileGuardrailsTest extends TestCase
{
    use ReconciliationFixtures;

    public function testAFluxIsOnlyAdoptedOnceTheWindowOfTheDepositIsClosed(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', '2022-02-01T11:30:00Z');
        $listing = [self::nature10('F1', 'OK', '2022-02-01T11:40:00Z')];
        $harness->gateway->willList($listing);
        $harness->gateway->willList($listing);

        $harness->clock->moveTo('2022-02-01T12:30:00Z');
        $atTheEnd = $harness->reconcileHandler()(self::command());
        $harness->clock->moveTo('2022-02-01T12:30:01Z');
        $afterTheEnd = $harness->reconcileHandler()(self::command());

        static::assertSame([], $atTheEnd->adopted);
        static::assertSame([$deposit->id()], $afterTheEnd->adopted);
        static::assertSame(DepositState::Received, $deposit->state());
    }

    public function testAnAdoptionIsMarkedAsDoneByTheReconciliation(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $harness->reconcileHandler()(self::command());

        static::assertTrue($deposit->adoptedByReconciliation());
        $adopted = array_values(array_filter($harness->outbox->events(), static fn(IntegrationEvent $event): bool => $event instanceof PublicAdopted));
        static::assertCount(1, $adopted);
        static::assertSame('F1', $adopted[0]->flowId);
        static::assertSame('received', $adopted[0]->state);
    }

    public function testASoleCandidateTheDeploymentDoesNotAdoptAloneGoesToManualReview(): void
    {
        $harness = Harness::inMemory(Policies::autoAdopting(false));
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertNull($deposit->flowId());
        static::assertCount(1, $report->needingReview);
        static::assertSame(ManualReviewItem::ADOPTION_NOT_AUTOMATIC, $report->needingReview[0]->reason);
        static::assertSame(['F1'], array_map(static fn(FlowId $flowId): string => $flowId->value(), $report->needingReview[0]->candidates));
    }

    public function testADepositOlderThanWhatUrssafKeepsIsReportedWithoutBeingListed(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $old = self::unknown($harness, 'd1', 'a', '2020-12-01T10:00:00Z');

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $harness->gateway->listCalls());
        static::assertCount(1, $report->needingReview);
        static::assertSame($old->id(), $report->needingReview[0]->depositId);
        static::assertSame(ManualReviewItem::OUT_OF_RETENTION, $report->needingReview[0]->reason);
        static::assertSame([], $report->needingReview[0]->candidates);
    }

    public function testTheListedRangeNeverStartsBeforeThirteenMonthsNorEndsAfterNow(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        self::unknown($harness, 'd1', 'a', '2021-01-01T11:30:00Z');
        self::unknown($harness, 'd2', 'b', '2022-02-01T12:00:00Z');
        $harness->gateway->willList([]);

        $harness->reconcileHandler()(self::command());

        static::assertCount(1, $harness->gateway->listCalls());
        static::assertSame('2021-01-01T12:00:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame('2022-02-01T12:00:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
    }

    public function testTheRangeIsCutLikeTheSynchronisationDoes(): void
    {
        $harness = Harness::inMemory(Policies::withMaxRange(new DateInterval('PT1H')));
        $first = self::unknown($harness, 'd1', 'a', '2022-02-01T08:00:00Z');
        $second = self::unknown($harness, 'd2', 'b', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T08:10:00Z')]);
        $harness->gateway->willList([]);
        $harness->gateway->willList([self::nature10('F2', 'OK', '2022-02-01T10:40:00Z')]);
        $harness->gateway->willList([]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$first->id(), $second->id()], $report->adopted);
        $ranges = array_map(static fn(array $call): string => $call[1]->toIso8601() . '/' . ($call[2]?->toIso8601() ?? 'open'), $harness->gateway->listCalls());
        static::assertSame([
            '2022-02-01T07:55:00Z/2022-02-01T08:55:00Z',
            '2022-02-01T08:55:00Z/2022-02-01T09:55:00Z',
            '2022-02-01T09:55:00Z/2022-02-01T10:55:00Z',
            '2022-02-01T10:55:00Z/2022-02-01T11:30:00Z',
        ], $ranges);
    }

    public function testAnExpiredDepositDoesNotKeepTheOthersFromBeingReconciled(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $old = self::unknown($harness, 'd1', 'a', '2020-12-01T10:00:00Z');
        $recent = self::unknown($harness, 'd2', 'b', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$recent->id()], $report->adopted);
        static::assertCount(1, $report->needingReview);
        static::assertSame($old->id(), $report->needingReview[0]->depositId);
        static::assertSame('2022-02-01T10:25:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
    }

    public function testARefusedListingIsReportedNotThrownAndNothingIsAdopted(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList(PollingConstraintViolated::withStatus(429, 10));
        $harness->gateway->willList(PollingConstraintViolated::withStatus(422, null));

        $withDelay = $harness->reconcileHandler()(self::command());
        $withoutDelay = $harness->reconcileHandler()(self::command());

        static::assertTrue($withDelay->refused);
        static::assertSame(10, $withDelay->retryAfterSeconds);
        static::assertSame(60, $withoutDelay->retryAfterSeconds);
        static::assertSame([], $withDelay->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
    }

    public function testAFluxNoDepositHoldsAndNoWindowContainsIsReportedForReview(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([
            self::nature10('F1', 'OK', '2022-02-01T10:40:00Z'),
            self::nature10('F9', 'OK', '2022-02-01T05:00:00Z'),
            self::nature10('F8', 'OK', null),
        ]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertCount(1, $report->adopted);
        static::assertSame(['F9', 'F8'], array_map(static fn(FlowId $flowId): string => $flowId->value(), $report->unattachedFlux));
        static::assertFalse($report->refused);
    }
}
