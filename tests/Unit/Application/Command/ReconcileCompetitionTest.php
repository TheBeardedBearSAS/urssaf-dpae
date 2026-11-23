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
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ReconcileCompetitionTest extends TestCase
{
    use ReconciliationFixtures;

    public function testTwoDepositsSharingACandidateWindowNeverAdoptIt(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $first = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $second = self::unknown($harness, 'd2', 'b', '2022-02-01T10:40:00Z');
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:45:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $first->state());
        static::assertSame(DepositState::OutcomeUnknown, $second->state());
        static::assertSame('2022-02-01T10:25:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame('2022-02-01T11:40:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
    }

    public function testTwoDepositsWithSeparateWindowsEachAdoptTheirOwnCandidate(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $first = self::unknown($harness, 'd1', 'a', '2022-02-01T08:00:00Z');
        $second = self::unknown($harness, 'd2', 'b', self::CLAIMED);
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T08:10:00Z'), self::nature10('F2', 'KO', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$first->id(), $second->id()], $report->adopted);
        static::assertSame('F1', $first->flowId()?->value());
        static::assertSame(DepositState::Received, $first->state());
        static::assertSame('F2', $second->flowId()?->value());
        static::assertSame(DepositState::Rejected, $second->state());
        static::assertSame('2022-02-01T07:55:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame('2022-02-01T11:30:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
    }

    public function testAnInFlightDepositWhoseWindowContainsTheCandidateBlocksTheAdoption(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $unknown = self::unknown($harness, 'd1', 'a', '2022-02-01T10:55:00Z');
        $inFlight = self::sent($harness, 'd2', 'tenant-1', 'b', '2022-02-01T11:50:00Z');
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T11:46:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $unknown->state());
        static::assertSame(DepositState::Depositing, $inFlight->state());
    }

    public function testAnInFlightDepositWhoseWindowDoesNotContainTheCandidateDoesNotBlock(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $unknown = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        self::sent($harness, 'd2', 'tenant-1', 'b', '2022-02-01T11:59:00Z');
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T11:26:00Z')]);
        $harness->clock->moveTo('2022-02-01T12:10:00Z');

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$unknown->id()], $report->adopted);
    }

    public function testADepositOfAnotherTenantIsNeitherATargetNorABlocker(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $mine = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $theirs = self::sent($harness, 'd2', 'tenant-c', 'b', self::CLAIMED);
        $theirs->markOutcomeUnknown('x', Samples::at(self::CLAIMED));
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$mine->id()], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $theirs->state());
    }

    public function testAFluxAlreadyHeldByALocalDepositIsNotACandidate(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $unknown = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $holder = self::sent($harness, 'd2', 'tenant-1', 'b', '2022-02-01T09:00:00Z');
        $holder->recordResult(SampleAnswers::receipt('F1'), Samples::at('2022-02-01T09:00:10Z'));
        $harness->gateway->willList([self::nature10('F1', 'OK', '2022-02-01T10:40:00Z')]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([], $report->adopted);
        static::assertSame(DepositState::OutcomeUnknown, $unknown->state());
    }

    public function testOnlyTheReceiptOrRejectionReturnIsACandidateAndOnceByFlux(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $deposit = self::unknown($harness, 'd1', 'a', self::CLAIMED);
        $harness->gateway->willList([
            SampleReturns::listed('F9', 11, 'OK', '2022-02-01T10:41:00Z', null),
            SampleReturns::listed('F1', 10, 'OK', '2022-02-01T10:40:00Z', 'OPS01'),
            SampleReturns::listed('F1', 10, 'OK', '2022-02-01T10:40:00Z', 'OPS02'),
            self::nature10('F8', 'OK', null),
        ]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame('F1', $deposit->flowId()?->value());
    }
}
