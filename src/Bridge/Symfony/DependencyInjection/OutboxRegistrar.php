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
use TheBeardedBear\UrssafDpae\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafDpae\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\PurgeOutboxCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\RelayOutboxCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Event\EventDispatcherIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Outbox\MessengerIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;

/**
 * The outbox (written by the UnitOfWork, which autowires it; ignored while disabled), its sink, relay and purge.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class OutboxRegistrar
{
    public const SINK_MESSENGER = 'messenger';
    public const SINK_EVENT_DISPATCHER = 'event_dispatcher';

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $outbox = $config['outbox'];
        if (!$outbox['enabled']) {
            $this->services->set(Outbox::class, IgnoredOutbox::class);

            return;
        }
        $this->registerOutbox($outbox['service'], $config['persistence']['driver'], $config['persistence']['entity_manager']);
        $this->registerSink($outbox['sink'], $outbox['bus']);
        $this->services->set(RelayOutboxHandler::class)->autowire()->public();
        $this->services->set(PurgeOutboxHandler::class)->autowire()->public();
        $this->services->set(RelayOutboxCommand::class)->autowire()->tag('console.command');
        $this->services->set(PurgeOutboxCommand::class)
            ->args([new Reference(PurgeOutboxHandler::class), new Reference(OperationLocks::class), new Definition(DateInterval::class, [$outbox['retention']])])
            ->tag('console.command');
    }

    private function registerOutbox(?string $service, string $driver, string $entityManager): void
    {
        if ($service !== null) {
            $this->services->alias(Outbox::class, $service);

            return;
        }
        if ($driver === BundleConfiguration::DRIVER_DOCTRINE) {
            $this->services->set(Outbox::class, DoctrineOutbox::class)->args([new Reference(PersistenceRegistrar::entityManagerId($entityManager))]);

            return;
        }
        $this->services->set(Outbox::class, InMemoryOutbox::class);
    }

    private function registerSink(string $sink, string $bus): void
    {
        if ($sink === self::SINK_MESSENGER) {
            $this->services->set(IntegrationEventSink::class, MessengerIntegrationEventSink::class)->args([new Reference($bus)]);

            return;
        }
        if ($sink === self::SINK_EVENT_DISPATCHER) {
            $this->services->set(IntegrationEventSink::class, EventDispatcherIntegrationEventSink::class)->args([new Reference('event_dispatcher')]);

            return;
        }
        $this->services->alias(IntegrationEventSink::class, $sink);
    }
}
