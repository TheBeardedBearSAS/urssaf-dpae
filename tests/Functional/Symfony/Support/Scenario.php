<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Steps of the workflow, taken through the public handlers of the test application.
 */
final class Scenario
{
    private function __construct() {}

    public static function deposit(Harness $harness, string $tenant, string $reference, string $variant): Deposit
    {
        return $harness->get(DepositDpaeHandler::class)(new DepositDpae(
            TenantId::fromString($tenant),
            ExternalReference::fromString($reference),
            DpaeFlow::fromXml(Flows::xml(DeclarationMode::Test, $variant), DeclarationMode::Test, TestIndicators::defaults()),
        ));
    }
}
