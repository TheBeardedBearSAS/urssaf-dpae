<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\DepositCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\DownloadReturnCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\ListReturnsCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\ReconcileCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\SyncReturnsCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;

/**
 * Driving adapters: console commands and Messenger handlers.
 */
final readonly class EntryPointRegistrar
{
    /** Commands of a single tenant, or of the configured tenants when --tenant is not given. */
    private const TENANT_COMMANDS = [
        DepositCommand::class,
        ListReturnsCommand::class,
        DownloadReturnCommand::class,
        SyncReturnsCommand::class,
        ReconcileCommand::class,
    ];

    private const MESSAGE_HANDLERS = [
        DepositDpaeMessageHandler::class,
        SynchronizeReturnsMessageHandler::class,
        ReconcileDepositsMessageHandler::class,
    ];

    public function __construct(private ServicesConfigurator $services) {}

    public function registerConsole(): void
    {
        foreach (self::TENANT_COMMANDS as $command) {
            $this->services->set($command)
                ->autowire()
                ->arg('$configuredTenants', '%' . UrssafDpaeBundle::PARAMETER_TENANTS . '%')
                ->tag('console.command');
        }
    }

    public function registerMessenger(): void
    {
        foreach (self::MESSAGE_HANDLERS as $handler) {
            $this->services->set($handler)->autowire()->tag('messenger.message_handler');
        }
    }
}
