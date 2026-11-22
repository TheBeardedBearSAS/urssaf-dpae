<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Text;

use LogicException;

/**
 * The delay of a refused polling: a report that says "refused" always carries one.
 */
final class RetryDelays
{
    /**
     * @throws LogicException when the report carries no delay (a refused report always does)
     */
    public static function seconds(?int $retryAfterSeconds): int
    {
        return $retryAfterSeconds ?? throw new LogicException('A refused polling carries the delay to wait.');
    }
}
