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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ReconcileAnsweredDepositsTest extends TestCase
{
    use ReconciliationFixtures;

    public function testAReceivedDepositWithoutFlowIdGainsItWhateverTheStatus(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', self::CLAIMED);
        $deposit->recordResult(SampleAnswers::receiptWithoutFlowId(), Samples::at(self::CLAIMED));
        $deposit->releaseEvents();
        $harness->gateway->willList([self::nature10('F1', 'EN_COURS', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
        static::assertSame([], $report->needingReview);
    }

    public function testARejectedDepositWithoutFlowIdAlsoGainsIt(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', self::CLAIMED);
        $deposit->recordResult(SampleAnswers::rejectionWithoutFlowId(), Samples::at(self::CLAIMED));
        $harness->gateway->willList([self::nature10('F1', 'KO', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame(DepositState::Rejected, $deposit->state());
        static::assertSame('F1', $deposit->flowId()?->value());
    }

    public function testAnAnsweredDepositWithoutCandidateIsNeverReportedForReview(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T01:00:00Z');
        $deposit->recordResult(SampleAnswers::receiptWithoutFlowId(), Samples::at('2022-02-01T01:00:00Z'));
        $harness->gateway->willList([]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->needingReview);
        static::assertNull($deposit->flowId());
    }

    public function testNothingIsListedWhenNoDepositNeedsReconciling(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $harness->deposits->save(Samples::deposit('pending', 'tenant-1', 'ref-1', 'a', self::CLAIMED));
        self::sent($harness, 'sent', 'tenant-1', 'b', '2022-02-01T11:55:00Z');
        $answered = self::sent($harness, 'answered', 'tenant-1', 'c', '2022-02-01T10:40:00Z');
        $answered->recordResult(SampleAnswers::receipt('F1'), Samples::at('2022-02-01T10:40:00Z'));

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $harness->gateway->listCalls());
        static::assertSame([], $report->adopted);
        static::assertSame([], $report->needingReview);
        static::assertSame([], $report->markedUnknown);
    }

    public function testTheFirstDepositOfTheTenantMayBeAnInFlightOne(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        self::sent($harness, 'd0', 'tenant-1', 'z', '2022-02-01T05:00:00Z')->recordResult(SampleAnswers::receipt('F0'), Samples::at('2022-02-01T05:00:10Z'));
        self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T11:58:00Z');
        $unknown = self::unknown($harness, 'd2', 'b', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$unknown->id()], $report->adopted);
    }

    public function testAnInFlightDepositAheadOfAnUnknownOneDoesNotHideIt(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        self::sent($harness, 'd1', 'tenant-1', 'a', '2022-02-01T11:50:00Z');
        self::unknown($harness, 'd2', 'b', '2022-02-01T11:55:00Z');
        $harness->gateway->willList([]);

        $harness->reconcileHandler()(self::command());

        static::assertCount(1, $harness->gateway->listCalls());
        static::assertSame('2022-02-01T11:50:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame('2022-02-01T12:00:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
    }

    public function testEveryChangeIsSavedWhenTheRepositoryKeepsCopies(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $unknown = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $stale = self::sent($harness, 'd2', 'tenant-1', 'b', '2022-02-01T05:00:00Z');
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$stale->id()], $report->markedUnknown);
        static::assertSame([$unknown->id()], $report->adopted);
        $adopted = $harness->deposits->get($unknown->id());
        static::assertSame(DepositState::Received, $adopted->state());
        static::assertSame('F1', $adopted->flowId()?->value());
        $swept = $harness->deposits->get($stale->id());
        static::assertSame(DepositState::OutcomeUnknown, $swept->state());
        static::assertSame('deposit.stale', $swept->lastErrorCode());
        static::assertSame($unknown->id()->value(), $harness->returns->find(Samples::tenant('tenant-1'), self::nature10('F1', 'OK', null)->identity())?->depositId()?->value());
    }
}
