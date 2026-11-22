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
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait SynchronizationFixtures
{
    private const TENANT = 'tenant-1';

    private static function policy(?DateInterval $maxRange, int $maxRefusals): WorkflowPolicy
    {
        return new WorkflowPolicy(
            3,
            new DateInterval('PT15M'),
            new DateInterval('PT5M'),
            new DateInterval('PT1H'),
            new DateInterval('PT6H'),
            new DateInterval('PT10M'),
            new DateInterval('P7D'),
            $maxRange,
            60,
            $maxRefusals,
            null,
        );
    }

    private static function command(): SynchronizeReturns
    {
        return new SynchronizeReturns(Samples::tenant(self::TENANT));
    }
}
