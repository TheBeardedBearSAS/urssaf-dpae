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

use DateInterval;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Service\DepositAdopter;
use TheBeardedBear\UrssafDpae\Application\Service\DepositUpdater;
use TheBeardedBear\UrssafDpae\Application\Service\FluxCandidates;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\SendSettlement;
use TheBeardedBear\UrssafDpae\Application\Service\StaleDepositSweeper;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;

/**
 * Application layer: workflow policy, services and use case handlers (the only public services).
 */
final readonly class ApplicationRegistrar
{
    /** Use case handlers exposed to the host application. */
    private const HANDLERS = [
        DepositDpaeHandler::class,
        ListReturnsHandler::class,
        SynchronizeReturnsHandler::class,
        DownloadReturnHandler::class,
        ReconcileUnknownDepositsHandler::class,
    ];

    private const SERVICES = [
        UnitOfWork::class,
        DepositUpdater::class,
        SendSettlement::class,
        StaleDepositSweeper::class,
        FluxCandidates::class,
        DepositAdopter::class,
        ReturnIngestion::class,
    ];

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param array{max_deposit_attempts: int, stale_deposit_after: string, reconciliation_skew: string, reconciliation_window: string, manual_review_after: string, polling_overlap: string, initial_lookback: string, polling_max_range: ?string, polling_retry_delay_seconds: int, max_polling_constraint_retries: int, auto_adopt: ?bool} $workflow
     */
    public function register(array $workflow): void
    {
        $this->services->set(WorkflowPolicy::class)->args([
            $workflow['max_deposit_attempts'],
            new Definition(DateInterval::class, [$workflow['stale_deposit_after']]),
            new Definition(DateInterval::class, [$workflow['reconciliation_skew']]),
            new Definition(DateInterval::class, [$workflow['reconciliation_window']]),
            new Definition(DateInterval::class, [$workflow['manual_review_after']]),
            new Definition(DateInterval::class, [$workflow['polling_overlap']]),
            new Definition(DateInterval::class, [$workflow['initial_lookback']]),
            $workflow['polling_max_range'] === null ? null : new Definition(DateInterval::class, [$workflow['polling_max_range']]),
            $workflow['polling_retry_delay_seconds'],
            $workflow['max_polling_constraint_retries'],
            $workflow['auto_adopt'],
        ]);
        foreach (self::SERVICES as $service) {
            $this->services->set($service)->autowire();
        }
        foreach (self::HANDLERS as $handler) {
            $definition = $this->services->set($handler)->autowire()->public();
            if ($handler === DepositDpaeHandler::class) {
                $definition->arg('$installationMode', new Reference(ServiceIds::INSTALLATION_MODE));
            }
        }
    }
}
