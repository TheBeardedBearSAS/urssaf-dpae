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
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class SynchronizeReturnsRefusalTest extends TestCase
{
    use SynchronizationFixtures;

    public function testAPollingRefusalStopsTheRunWithoutMovingTheCursor(): void
    {
        $harness = Harness::inMemory(self::policy(null, 2));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:05:00Z'), 0));
        $harness->gateway->willList(PollingConstraintViolated::withStatus(429, 30));
        $harness->gateway->willList(PollingConstraintViolated::withStatus(422, null));
        $harness->gateway->willList([]);

        $first = $harness->syncHandler()(self::command());
        $second = $harness->syncHandler()(self::command());

        static::assertTrue($first->refused);
        static::assertFalse($first->needsAttention);
        static::assertSame(30, $first->retryAfterSeconds);
        static::assertSame(0, $first->discovered);
        static::assertSame(0, $first->windows);
        static::assertTrue($second->refused);
        static::assertTrue($second->needsAttention);
        static::assertSame(60, $second->retryAfterSeconds);
        $cursor = $harness->cursors->find(Samples::tenant(self::TENANT));
        static::assertInstanceOf(SyncCursor::class, $cursor);
        static::assertSame('2022-02-01T10:00:00Z', $cursor->position()?->toIso8601());
        static::assertSame('2022-02-01T10:05:00Z', $cursor->lastSuccessAt()?->toIso8601());
        static::assertSame(2, $cursor->consecutiveRefusals());

        $harness->syncHandler()(self::command());

        static::assertSame(0, $harness->cursors->find(Samples::tenant(self::TENANT))?->consecutiveRefusals());
    }

    public function testAFirstRefusalOfATenantNeverSeenCountsFromZero(): void
    {
        $harness = Harness::inMemory(self::policy(null, 1));
        $harness->gateway->willList(PollingConstraintViolated::withStatus(429, null));

        $report = $harness->syncHandler()(self::command());

        static::assertTrue($report->needsAttention);
        $cursor = $harness->cursors->find(Samples::tenant(self::TENANT));
        static::assertInstanceOf(SyncCursor::class, $cursor);
        static::assertNull($cursor->position());
        static::assertSame(1, $cursor->consecutiveRefusals());
    }

    public function testAnotherFailureLeavesTheCursorAndTheRefusalCountAlone(): void
    {
        $harness = Harness::inMemory(self::policy(null, 5));
        $harness->gateway->willList(UrssafUnavailable::withStatus(Operation::ListReturns, 503, null));

        try {
            $harness->syncHandler()(self::command());
            static::fail('UrssafUnavailable expected.');
        } catch (UrssafUnavailable) {
            static::assertNull($harness->cursors->find(Samples::tenant(self::TENANT)));
        }
    }

    public function testARefusalInALaterWindowKeepsTheCursorAndReportsWhatWasStored(): void
    {
        $harness = Harness::inMemory(self::policy(new DateInterval('PT1H'), 5));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T09:30:00Z'), null, 0));
        $harness->gateway->willList([SampleReturns::listed('F1', 10, 'OK', '2022-02-01T10:00:00Z', null)]);
        $harness->gateway->willList(PollingConstraintViolated::withStatus(429, 5));

        $report = $harness->syncHandler()(self::command());

        static::assertTrue($report->refused);
        static::assertSame(1, $report->discovered);
        static::assertSame(1, $report->windows);
        static::assertSame('2022-02-01T09:30:00Z', $harness->cursors->find(Samples::tenant(self::TENANT))?->position()?->toIso8601());
        static::assertCount(1, $harness->outbox->events());
    }
}
