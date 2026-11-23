<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use Psr\Clock\ClockInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\PsrClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;

/**
 * Container factory of the clocks of the package: the application "clock" service (PSR-20) when it exists, the UTC
 * system clock otherwise (symfony/clock is not required).
 */
final class ClockFactory
{
    public static function psr(?ClockInterface $applicationClock): ClockInterface
    {
        return $applicationClock ?? new SystemClock();
    }

    public static function port(ClockInterface $clock): Clock
    {
        return new PsrClock($clock);
    }
}
