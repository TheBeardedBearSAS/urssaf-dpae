<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use DateInterval;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;

/**
 * Workflow policies of the tests: the package defaults but for the one tunable a test is about.
 */
final class Policies
{
    public static function autoAdopting(?bool $forced): WorkflowPolicy
    {
        return self::with(null, $forced);
    }

    public static function withMaxRange(DateInterval $range): WorkflowPolicy
    {
        return self::with($range, null);
    }

    private static function with(?DateInterval $range, ?bool $autoAdopt): WorkflowPolicy
    {
        return new WorkflowPolicy(
            3,
            new DateInterval('PT15M'),
            new DateInterval('PT5M'),
            new DateInterval('PT1H'),
            new DateInterval('PT6H'),
            new DateInterval('PT2H'),
            new DateInterval('P7D'),
            $range,
            60,
            5,
            $autoAdopt,
        );
    }
}
