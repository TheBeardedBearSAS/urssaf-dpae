<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Clock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;

final class SystemClockTest extends TestCase
{
    public function testItReadsTheCurrentTimeInUtc(): void
    {
        $before = time();
        $now = (new SystemClock())->now();
        $after = time();

        static::assertSame('UTC', $now->getTimezone()->getName());
        static::assertGreaterThanOrEqual($before, $now->getTimestamp());
        static::assertLessThanOrEqual($after, $now->getTimestamp());
    }

    public function testItIsTheApplicationClockToo(): void
    {
        $port = static fn(Clock $clock): DateTimeImmutable => $clock->now();

        static::assertSame('UTC', $port(new SystemClock())->getTimezone()->getName());
    }
}
