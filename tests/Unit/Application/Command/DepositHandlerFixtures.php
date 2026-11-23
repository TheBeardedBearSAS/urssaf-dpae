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

use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait DepositHandlerFixtures
{
    private static function command(string $variant, string $reference, string $tenant): DepositDpae
    {
        return new DepositDpae(Samples::tenant($tenant), Samples::reference($reference), Samples::flow(DeclarationMode::Test, $variant));
    }

    private static function harness(): Harness
    {
        return Harness::inMemory(WorkflowPolicy::defaults());
    }
}
