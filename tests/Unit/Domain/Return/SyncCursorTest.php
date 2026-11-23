<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class SyncCursorTest extends TestCase
{
    public function testAnInitialCursorHasNoPositionNorRefusal(): void
    {
        $cursor = SyncCursor::initial(Samples::tenant('tenant-1'));

        static::assertSame('tenant-1', $cursor->tenantId()->value());
        static::assertNull($cursor->position());
        static::assertNull($cursor->lastSuccessAt());
        static::assertSame(0, $cursor->consecutiveRefusals());
    }

    public function testAStoredCursorIsRestored(): void
    {
        $cursor = SyncCursor::of(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:05:00Z'), 2);

        static::assertSame('2022-02-01T10:00:00Z', $cursor->position()?->toIso8601());
        static::assertSame('2022-02-01T10:05:00Z', $cursor->lastSuccessAt()?->toIso8601());
        static::assertSame(2, $cursor->consecutiveRefusals());
    }

    public function testARefusalCountIsNeverNegative(): void
    {
        static::assertSame(0, SyncCursor::of(Samples::tenant('t'), null, null, 0)->consecutiveRefusals());

        try {
            SyncCursor::of(Samples::tenant('t'), null, null, -1);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('consecutive_refusals.negative', $e->violationCode());
        }
    }

    public function testAdvancingMovesThePositionAndForgetsTheRefusals(): void
    {
        $refused = SyncCursor::initial(Samples::tenant('tenant-1'))->refused()->refused();

        $advanced = $refused->advancedTo(Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:05:00Z'));

        static::assertSame('tenant-1', $advanced->tenantId()->value());
        static::assertSame('2022-02-01T10:00:00Z', $advanced->position()?->toIso8601());
        static::assertSame('2022-02-01T10:05:00Z', $advanced->lastSuccessAt()?->toIso8601());
        static::assertSame(0, $advanced->consecutiveRefusals());
        static::assertSame(2, $refused->consecutiveRefusals());
    }

    public function testARefusalKeepsThePositionAndCounts(): void
    {
        $cursor = SyncCursor::of(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T10:05:00Z'), 1);

        $refused = $cursor->refused();

        static::assertSame('tenant-1', $refused->tenantId()->value());
        static::assertSame('2022-02-01T10:00:00Z', $refused->position()?->toIso8601());
        static::assertSame('2022-02-01T10:05:00Z', $refused->lastSuccessAt()?->toIso8601());
        static::assertSame(2, $refused->consecutiveRefusals());
    }
}
