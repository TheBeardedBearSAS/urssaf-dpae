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

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Credentials\LazyCredentialsProvider;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Event\SymfonyEventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\RandomIdGenerator;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafDpae\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;

/**
 * Generic infrastructure: clocks, identifiers, events, credentials, access token store.
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class InfrastructureRegistrar
{
    public const LOGGER_TAG = 'monolog.logger';
    public const LOGGER_CHANNEL = 'urssaf_dpae';

    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $this->services->set(ServiceIds::PSR_CLOCK, ClockInterface::class)
            ->factory(ClockFactory::class . '::psr')
            ->args([new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE)]);
        $this->services->set(Clock::class, Clock::class)
            ->factory(ClockFactory::class . '::port')
            ->args([new Reference(ServiceIds::PSR_CLOCK)]);
        $this->services->set(IdGenerator::class, RandomIdGenerator::class)->args([new Definition(SecureRandomBytes::class)]);
        $this->services->set(EventPublisher::class, SymfonyEventPublisher::class)->args([new Reference('event_dispatcher')]);
        $this->services->set(FailureReporter::class, PsrLoggerFailureReporter::class)
            ->args([new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE)])
            ->tag(self::LOGGER_TAG, ['channel' => self::LOGGER_CHANNEL]);
        $this->services->set(TestIndicators::class)
            ->factory(TestIndicators::class . '::of')
            ->args([$config['test_indicator']['real'], $config['test_indicator']['test']]);
        $this->registerCredentials($config['credentials_provider'], $config['credentials']);
        $this->registerTokenStore($config['token_store']['service']);
    }

    /**
     * @param array<string, array{siret: string, nom: string, prenom: string, password: string, service: int}> $credentials
     */
    private function registerCredentials(?string $provider, array $credentials): void
    {
        if ($provider !== null) {
            $this->services->alias(CredentialsProvider::class, $provider);

            return;
        }
        // One closure per tenant: its placeholders are resolved when the tenant is used, a failing tenant breaks only itself.
        $factories = [];
        foreach ($credentials as $tenantId => $tenant) {
            $factories[$tenantId] = new ServiceClosureArgument(
                (new Definition(DeclarantCredentials::class, [$tenant['siret'], $tenant['nom'], $tenant['prenom'], $tenant['password'], $tenant['service']]))
                    ->setFactory([DeclarantCredentialsFactory::class, 'create']),
            );
        }
        $this->services->set(CredentialsProvider::class, LazyCredentialsProvider::class)->args([$factories]);
    }

    private function registerTokenStore(?string $service): void
    {
        if ($service !== null) {
            $this->services->alias(TokenStore::class, $service);

            return;
        }
        $this->services->set(TokenStore::class, InMemoryTokenStore::class);
    }
}
