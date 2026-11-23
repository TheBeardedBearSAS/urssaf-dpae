<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Standalone;

use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;

/**
 * The use cases of the package for a host without a framework, built by {@see UrssafDpaeBuilder}:
 *
 *     $dpae = UrssafDpaeBuilder::create($transport, $credentials, ApiConfiguration::defaults())->build();
 *     $deposit = $dpae->depositDpae()(new DepositDpae($tenant, $reference, $flow));
 *
 * Each accessor returns a ready handler; call it with its command.
 */
final readonly class UrssafDpae
{
    public function __construct(
        private DpaeGateway $gateway,
        private DepositDpaeHandler $depositDpae,
        private ListReturnsHandler $listReturns,
        private SynchronizeReturnsHandler $synchronizeReturns,
        private DownloadReturnHandler $downloadReturn,
        private ReconcileUnknownDepositsHandler $reconcileUnknownDeposits,
    ) {}

    public function gateway(): DpaeGateway
    {
        return $this->gateway;
    }

    public function depositDpae(): DepositDpaeHandler
    {
        return $this->depositDpae;
    }

    public function listReturns(): ListReturnsHandler
    {
        return $this->listReturns;
    }

    public function synchronizeReturns(): SynchronizeReturnsHandler
    {
        return $this->synchronizeReturns;
    }

    public function downloadReturn(): DownloadReturnHandler
    {
        return $this->downloadReturn;
    }

    public function reconcileUnknownDeposits(): ReconcileUnknownDepositsHandler
    {
        return $this->reconcileUnknownDeposits;
    }
}
