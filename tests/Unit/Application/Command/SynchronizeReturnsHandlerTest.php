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
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class SynchronizeReturnsHandlerTest extends TestCase
{
    use SynchronizationFixtures;

    public function testAFirstRunLooksBackByTheInitialLookbackAndMovesTheCursorToTheStartMinusTheOverlap(): void
    {
        $harness = Harness::inMemory(self::policy(null, 5));
        $harness->gateway->willList([
            SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null),
            SampleReturns::listed('F2', 10, 'OK', '2022-02-01T11:30:00Z', null),
        ]);

        $report = $harness->syncHandler()(self::command());

        static::assertSame(2, $report->discovered);
        static::assertSame(1, $report->windows);
        static::assertFalse($report->refused);
        static::assertFalse($report->needsAttention);
        static::assertNull($report->retryAfterSeconds);
        static::assertCount(1, $harness->gateway->listCalls());
        static::assertSame(self::TENANT, $harness->gateway->listCalls()[0][0]->value());
        static::assertSame('2022-01-25T12:00:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertNull($harness->gateway->listCalls()[0][2]);
        $cursor = $harness->cursors->find(Samples::tenant(self::TENANT));
        static::assertInstanceOf(SyncCursor::class, $cursor);
        static::assertSame('2022-02-01T11:50:00Z', $cursor->position()?->toIso8601());
        static::assertSame(Samples::NOW, $cursor->lastSuccessAt()?->toIso8601());
        static::assertSame(0, $cursor->consecutiveRefusals());
        static::assertCount(2, $harness->outbox->events());
    }

    public function testTheNextRunStartsFromTheCursorAndKnownReturnsAreNotDiscoveredAgain(): void
    {
        $harness = Harness::inMemory(self::policy(null, 5));
        $reference = SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null);
        $harness->gateway->willList([$reference]);
        $harness->gateway->willList([$reference, SampleReturns::listed('F2', 10, 'OK', '2022-02-01T12:30:00Z', null)]);
        $harness->syncHandler()(self::command());
        $harness->clock->moveTo('2022-02-01T13:00:00Z');

        $report = $harness->syncHandler()(self::command());

        static::assertSame(1, $report->discovered);
        static::assertSame('2022-02-01T11:50:00Z', $harness->gateway->listCalls()[1][1]->toIso8601());
        static::assertSame('2022-02-01T12:50:00Z', $harness->cursors->find(Samples::tenant(self::TENANT))?->position()?->toIso8601());
        static::assertCount(2, $harness->outbox->events());
    }

    public function testAnAncientCursorIsRaisedToThirteenMonthsAndNoFurther(): void
    {
        $harness = Harness::inMemory(self::policy(null, 5));
        $floor = '2021-01-01T12:00:00Z';
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2021-01-01T11:59:59Z'), null, 0));
        $harness->gateway->willList([]);
        $harness->gateway->willList([]);
        $harness->syncHandler()(self::command());
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at($floor), null, 0));

        $harness->syncHandler()(self::command());
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2021-01-01T12:00:01Z'), null, 0));
        $harness->gateway->willList([]);
        $harness->syncHandler()(self::command());

        static::assertSame($floor, $harness->gateway->listCalls()[0][1]->toIso8601());
        static::assertSame($floor, $harness->gateway->listCalls()[1][1]->toIso8601());
        static::assertSame('2021-01-01T12:00:01Z', $harness->gateway->listCalls()[2][1]->toIso8601());
    }

    public function testAFirstRunWithAnInitialLookbackBeyondThirteenMonthsIsCapped(): void
    {
        $harness = Harness::inMemory(new WorkflowPolicy(3, new DateInterval('PT15M'), new DateInterval('PT5M'), new DateInterval('PT1H'), new DateInterval('PT6H'), new DateInterval('PT10M'), new DateInterval('P2Y'), null, 60, 5, null));
        $harness->gateway->willList([]);

        $harness->syncHandler()(self::command());

        static::assertSame('2021-01-01T12:00:00Z', $harness->gateway->listCalls()[0][1]->toIso8601());
    }

    public function testACursorInTheFutureIsBroughtBackToNow(): void
    {
        $harness = Harness::inMemory(self::policy(null, 5));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T12:00:01Z'), null, 0));
        $harness->gateway->willList([]);
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T12:00:00Z'), null, 0));
        $harness->gateway->willList([]);

        $harness->syncHandler()(self::command());

        static::assertSame(Samples::NOW, $harness->gateway->listCalls()[0][1]->toIso8601());
    }
}
