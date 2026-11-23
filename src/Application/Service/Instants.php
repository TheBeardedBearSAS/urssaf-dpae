<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use DateInterval;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Calendar arithmetic on instants for the policies of the workflow (months and days follow the UTC calendar).
 */
final class Instants
{
    public static function plus(Instant $instant, DateInterval $interval): Instant
    {
        return Instant::fromDateTime($instant->toDateTimeImmutable()->add($interval));
    }

    public static function minus(Instant $instant, DateInterval $interval): Instant
    {
        return Instant::fromDateTime($instant->toDateTimeImmutable()->sub($interval));
    }
}
