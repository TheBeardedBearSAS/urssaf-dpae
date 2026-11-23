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
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * A run reads at most `WorkflowPolicy::RECONCILIATION_BATCH` deposits, the oldest claims first: it only adopts what the
 * deposits it read can decide (ADR 0005).
 */
final class ReconcileBatchTest extends TestCase
{
    use ReconciliationFixtures;

    public function testAFullBatchOnlyAdoptsForDepositsNoUnreadDepositCanCompeteWith(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $base = Samples::at('2022-01-10T00:00:00Z')->toDateTimeImmutable();
        $deposits = [];
        for ($i = 0; $i < WorkflowPolicy::RECONCILIATION_BATCH; ++$i) {
            $claimedAt = $base->add(new DateInterval(\sprintf('PT%dH', 2 * $i)))->format('Y-m-d\\TH:i:s\\Z');
            $deposits[] = self::unknown($harness, 'd' . $i, 'v' . $i, $claimedAt);
        }
        $harness->gateway->willList([
            self::nature10('F-first', 'OK', '2022-01-10T00:10:00Z'),
            self::nature10('F-penultimate', 'OK', '2022-01-26T12:10:00Z'),
            self::nature10('F-last', 'OK', '2022-01-26T14:10:00Z'),
            self::nature10('F-gap', 'OK', '2022-01-10T01:30:00Z'),
            self::nature10('F-unread', 'OK', '2022-01-30T10:00:00Z'),
            self::nature10('F-undated', 'OK', null),
        ]);

        $report = $harness->reconcileHandler()(self::command());

        static::assertSame([$deposits[0]->id(), $deposits[WorkflowPolicy::RECONCILIATION_BATCH - 2]->id()], $report->adopted);
        static::assertSame('F-first', $deposits[0]->flowId()?->value());
        static::assertNull($deposits[WorkflowPolicy::RECONCILIATION_BATCH - 1]->flowId());
        static::assertSame(['F-gap'], array_map(static fn(FlowId $flowId): string => $flowId->value(), $report->unattachedFlux));
    }
}
