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

use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessageHandler;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

/**
 * The Messenger handlers of a test application on the simulated URSSAF.
 */
final class Handlers
{
    private function __construct() {}

    public static function boot(): Harness
    {
        return Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, false);
    }

    public static function message(string $reference): DepositDpaeMessage
    {
        return DepositDpaeMessage::of('tenant-1', $reference, DeclarationMode::Test, Flows::xml(DeclarationMode::Test, 'a'));
    }

    public static function deposit(Harness $harness): DepositDpaeMessageHandler
    {
        return $harness->privateService(DepositDpaeMessageHandler::class, DepositDpaeMessageHandler::class);
    }

    public static function synchronize(Harness $harness): SynchronizeReturnsMessageHandler
    {
        return $harness->privateService(SynchronizeReturnsMessageHandler::class, SynchronizeReturnsMessageHandler::class);
    }

    public static function reconcile(Harness $harness): ReconcileDepositsMessageHandler
    {
        return $harness->privateService(ReconcileDepositsMessageHandler::class, ReconcileDepositsMessageHandler::class);
    }
}
