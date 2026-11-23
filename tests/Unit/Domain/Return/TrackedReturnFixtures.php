<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait TrackedReturnFixtures
{
    private const T0 = '2022-02-01T10:00:00Z';
    private const T1 = '2022-02-01T11:00:00Z';

    private static function tracked(): TrackedReturn
    {
        return TrackedReturn::discover(Samples::tenant('tenant-1'), SampleReturns::listed('F1', 10, 'EN_COURS', self::T0, null), null, Samples::at(self::T0));
    }

    private static function trackedWithoutDocument(): TrackedReturn
    {
        return TrackedReturn::discover(Samples::tenant('tenant-1'), SampleReturns::listedWithoutDocument('F1', 10, 'EN_COURS', self::T0, null), null, Samples::at(self::T0));
    }
}
