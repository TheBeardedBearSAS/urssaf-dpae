<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http;

use DateTimeImmutable;

/**
 * Elapsed time between two readings of the clock, for the logs.
 *
 * @internal
 */
final class Duration
{
    public static function millisecondsSince(DateTimeImmutable $start, DateTimeImmutable $end): int
    {
        return intdiv(self::epochMicroseconds($end) - self::epochMicroseconds($start), 1000);
    }

    private static function epochMicroseconds(DateTimeImmutable $instant): int
    {
        return (int) $instant->format('Uu');
    }
}
