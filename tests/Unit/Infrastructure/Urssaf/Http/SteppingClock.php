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
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * A clock that starts at a known instant (Sun, 06 Nov 1994 08:49:37 UTC) and moves forward by a fixed step at each reading.
 */
final class SteppingClock implements ClockInterface
{
    private int $microseconds = 0;

    public function __construct(private readonly int $stepMilliseconds) {}

    public function now(): DateTimeImmutable
    {
        $now = (new DateTimeImmutable(\sprintf('@%d.%06d', 784_111_777 + intdiv($this->microseconds, 1_000_000), $this->microseconds % 1_000_000)))->setTimezone(new DateTimeZone('UTC'));
        $this->microseconds += $this->stepMilliseconds * 1000;

        return $now;
    }
}
