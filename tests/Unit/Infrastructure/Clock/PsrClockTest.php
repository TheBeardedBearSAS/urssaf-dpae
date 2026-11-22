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
use Psr\Clock\ClockInterface;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\PsrClock;

final class PsrClockTest extends TestCase
{
    public function testItReadsTheWrappedPsrClock(): void
    {
        $moment = new DateTimeImmutable('2022-02-01T12:00:00Z');
        $psr = new class ($moment) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $moment) {}

            public function now(): DateTimeImmutable
            {
                return $this->moment;
            }
        };

        static::assertSame($moment, (new PsrClock($psr))->now());
    }
}
