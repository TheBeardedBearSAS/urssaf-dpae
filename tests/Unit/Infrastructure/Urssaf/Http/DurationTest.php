<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\Duration;

final class DurationTest extends TestCase
{
    public function testItCountsWholeMilliseconds(): void
    {
        $start = new DateTimeImmutable('2022-01-30 08:30:00.250000');

        static::assertSame(0, Duration::millisecondsSince($start, $start));
        static::assertSame(1250, Duration::millisecondsSince($start, new DateTimeImmutable('2022-01-30 08:30:01.500000')));
        static::assertSame(1, Duration::millisecondsSince($start, new DateTimeImmutable('2022-01-30 08:30:00.251999')));
        static::assertSame(60_000, Duration::millisecondsSince($start, new DateTimeImmutable('2022-01-30 08:31:00.250000')));
    }
}
