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
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ReconcileReviewTest extends TestCase
{
    use ReconciliationFixtures;

    public function testAnUnsettledUnknownOutcomeIsReportedOnlyOnceItIsOlderThanTheReviewDelay(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $listing = [self::nature10('F1', 'OK', '2022-02-01T10:40:00Z'), self::nature10('F2', 'OK', '2022-02-01T10:50:00Z')];
        $harness->gateway->willList($listing);
        $harness->gateway->willList($listing);
        $harness->gateway->willList($listing);

        $harness->clock->moveTo('2022-02-01T16:29:59Z');
        $early = $harness->reconcileHandler()(self::command());
        $harness->clock->moveTo('2022-02-01T16:30:00Z');
        $atTheEdge = $harness->reconcileHandler()(self::command());
        $harness->clock->moveTo('2022-02-01T16:30:01Z');
        $late = $harness->reconcileHandler()(self::command());

        static::assertSame([], $early->needingReview);
        static::assertSame([], $atTheEdge->needingReview);
        static::assertCount(1, $late->needingReview);
        $item = $late->needingReview[0];
        static::assertSame($deposit->id(), $item->depositId);
        static::assertSame('ref-d1', $item->externalReference->value());
        static::assertTrue($item->fingerprint->equals(Samples::fingerprint('a')));
        static::assertSame(self::CLAIMED, $item->claimedAt->toIso8601());
        static::assertSame(['F1', 'F2'], array_map(static fn(FlowId $flowId): string => $flowId->value(), $item->candidates));
    }

    public function testADepositWithoutAnyCandidateIsReportedWithNoCandidate(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        self::unknown($harness, 'd1', 'a', '2022-02-01T05:00:00Z');
        $harness->gateway->willList([]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertCount(1, $report->needingReview);
        static::assertSame([], $report->needingReview[0]->candidates);
    }
}
