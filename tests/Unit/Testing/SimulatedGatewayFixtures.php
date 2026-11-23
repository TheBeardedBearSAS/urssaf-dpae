<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Testing;

use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SequentialIdGenerator;

trait SimulatedGatewayFixtures
{
    private static function gateway(FixedClock $clock): SimulatedDpaeGateway
    {
        return new SimulatedDpaeGateway($clock, new SequentialIdGenerator());
    }
}
