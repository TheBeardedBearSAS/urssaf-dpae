<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

final class BundleBootTest extends TestCase
{
    public function testTheBundleBoots(): void
    {
        $harness = Harness::bootDefault([]);

        static::assertInstanceOf(DepositDpaeHandler::class, $harness->get(DepositDpaeHandler::class));
        static::assertInstanceOf(DpaeGateway::class, $harness->privateService(DpaeGateway::class, DpaeGateway::class));
    }
}
