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
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class SynchronizeReturnsWindowsTest extends TestCase
{
    use SynchronizationFixtures;

    public function testALongRangeIsWalkedInConsecutiveWindows(): void
    {
        $harness = Harness::inMemory(self::policy(new DateInterval('PT1H'), 5));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T09:30:00Z'), null, 0));
        $harness->gateway->willList([SampleReturns::listed('F1', 10, 'OK', '2022-02-01T10:00:00Z', null)]);
        $harness->gateway->willList([SampleReturns::listed('F2', 10, 'OK', '2022-02-01T11:00:00Z', null)]);
        $harness->gateway->willList([SampleReturns::listed('F3', 10, 'OK', '2022-02-01T12:00:00Z', null)]);

        $report = $harness->syncHandler()(self::command());

        static::assertSame(3, $report->windows);
        static::assertSame(3, $report->discovered);
        $bounds = array_map(static fn(array $call): array => [$call[1]->toIso8601(), $call[2]?->toIso8601()], $harness->gateway->listCalls());
        static::assertSame([
            ['2022-02-01T09:30:00Z', '2022-02-01T10:30:00Z'],
            ['2022-02-01T10:30:00Z', '2022-02-01T11:30:00Z'],
            ['2022-02-01T11:30:00Z', '2022-02-01T12:00:00Z'],
        ], $bounds);
        static::assertSame('2022-02-01T11:50:00Z', $harness->cursors->find(Samples::tenant(self::TENANT))?->position()?->toIso8601());
    }

    public function testARangeThatIsExactlyTheLimitIsOneWindow(): void
    {
        $harness = Harness::inMemory(self::policy(new DateInterval('PT1H'), 5));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T11:00:00Z'), null, 0));
        $harness->gateway->willList([]);

        $report = $harness->syncHandler()(self::command());

        static::assertSame(1, $report->windows);
        static::assertSame('2022-02-01T12:00:00Z', $harness->gateway->listCalls()[0][2]?->toIso8601());
    }

    public function testAnEmptyRangeCallsNothing(): void
    {
        $harness = Harness::inMemory(self::policy(new DateInterval('PT1H'), 5));
        $harness->cursors->save(SyncCursor::of(Samples::tenant(self::TENANT), Samples::at('2022-02-01T12:00:00Z'), null, 0));

        $report = $harness->syncHandler()(self::command());

        static::assertSame(0, $report->windows);
        static::assertSame([], $harness->gateway->listCalls());
        static::assertSame('2022-02-01T11:50:00Z', $harness->cursors->find(Samples::tenant(self::TENANT))?->position()?->toIso8601());
    }
}
