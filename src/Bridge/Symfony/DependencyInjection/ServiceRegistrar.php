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
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Scheduler\UrssafDpaeScheduleProvider;

/**
 * Declares the services of the bundle from its processed configuration. Services are private (the default of the
 * container configurator), except the use case handlers (the entry points of the host application).
 *
 * @phpstan-type Config array{
 *     declaration_mode: string,
 *     hosts: array{authentication: ?string, deposit: ?string, consultation: ?string, download: ?string},
 *     concentrator_port: int,
 *     authentication_encoding: string,
 *     deposit_content_type: string,
 *     max_flow_bytes: int,
 *     max_response_bytes: int,
 *     returns_time_zone: string,
 *     test_indicator: array{real: string, test: string},
 *     http_client: ?string,
 *     http_timeout: float,
 *     credentials_provider: ?string,
 *     credentials: array<string, array{siret: string, nom: string, prenom: string, password: string, service: int}>,
 *     tenants: list<string>,
 *     token_store: array{service: ?string},
 *     gateway: array{simulated: bool},
 *     lock: array{enabled: bool|string, factory: string, ttl: float},
 *     scheduler: array{enabled: bool, frequencies: array<string, ?string>},
 *     outbox: array{enabled: bool, service: ?string, sink: string, bus: string, retention: string},
 *     workflow: array{max_deposit_attempts: int, stale_deposit_after: string, reconciliation_skew: string, reconciliation_window: string, manual_review_after: string, polling_overlap: string, initial_lookback: string, polling_max_range: ?string, polling_retry_delay_seconds: int, max_polling_constraint_retries: int, auto_adopt: ?bool},
 *     persistence: array{driver: string, deposit_repository: ?string, return_repository: ?string, sync_cursor_repository: ?string, return_content_store: ?string, transaction_manager: ?string, entity_manager: string, keep_flows: bool},
 *     messenger: array{enabled: bool}
 * }
 */
final readonly class ServiceRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        (new InfrastructureRegistrar($this->services))->register($config);
        (new GatewayRegistrar($this->services))->register($config);
        (new PersistenceRegistrar($this->services))->register($config['persistence']);
        (new LockRegistrar($this->services))->register($config['lock']);
        (new OutboxRegistrar($this->services))->register($config);
        (new ApplicationRegistrar($this->services))->register($config['workflow']);
        $entryPoints = new EntryPointRegistrar($this->services);
        $entryPoints->registerConsole();
        (new DiagnosticRegistrar($this->services))->register($config);
        if ($config['messenger']['enabled']) {
            $entryPoints->registerMessenger();
        }
        if ($config['scheduler']['enabled']) {
            $this->services->set(UrssafDpaeScheduleProvider::class)
                ->args([$config['outbox']['enabled']
                    ? $config['scheduler']['frequencies']
                    // Without the outbox, its commands do not exist.
                    : [...$config['scheduler']['frequencies'], 'relay_outbox' => null, 'purge_outbox' => null]])
                ->tag('scheduler.schedule_provider', ['name' => UrssafDpaeScheduleProvider::NAME]);
        }
    }
}
