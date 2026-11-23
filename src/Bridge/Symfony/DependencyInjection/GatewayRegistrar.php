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

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\DpaeTokenProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;

/**
 * The URSSAF HTTP gateway and everything it needs: PSR-18 client, PSR-17 factories, configuration, options. The
 * simulated gateway replaces it when "gateway.simulated" is on, through {@see SimulatedGatewayFactory} (runtime guard).
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class GatewayRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $this->services->set(ServiceIds::SYMFONY_HTTP_CLIENT, HttpClientInterface::class)
            ->factory(HttpClient::class . '::create')
            // Never follow a redirect: a 307 / 308 would resend the deposit POST (ADR 0002, 0004).
            ->args([['timeout' => $config['http_timeout'], 'max_redirects' => 0]]);
        // Also provides the PSR-17 request and stream factories, whatever the PSR-18 client in use.
        $this->services->set(ServiceIds::PSR18_CLIENT, Psr18Client::class)->args([new Reference(ServiceIds::SYMFONY_HTTP_CLIENT)]);
        // Resolved at runtime: the mode is usually an "%env(URSSAF_DPAE_DECLARATION_MODE)%" placeholder.
        $this->services->set(ServiceIds::INSTALLATION_MODE, DeclarationMode::class)
            ->factory(InstallationModeFactory::class . '::create')
            ->args([$config['declaration_mode']]);
        $hosts = [];
        foreach (DpaeService::cases() as $service) {
            $hosts[$service->value] = $config['hosts'][$service->value];
        }
        $this->services->set(ServiceIds::API_CONFIGURATION, ApiConfiguration::class)
            ->factory(ApiConfigurationFactory::class . '::create')
            ->args([
                new Reference(ServiceIds::INSTALLATION_MODE),
                $hosts,
                $config['concentrator_port'],
                $config['authentication_encoding'],
                $config['deposit_content_type'],
                $config['max_flow_bytes'],
                $config['max_response_bytes'],
                $config['returns_time_zone'],
            ]);
        $client = new Reference($config['http_client'] ?? ServiceIds::PSR18_CLIENT);
        $factories = new Reference(ServiceIds::PSR18_CLIENT);
        $this->services->set(ServiceIds::HTTP_TRANSPORT, HttpTransport::class)->args([$client, $factories, $factories]);
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $this->services->set(ServiceIds::GATEWAY_OPTIONS, GatewayOptions::class)
            ->factory(GatewayFactory::class . '::options')
            ->args([new Reference(ServiceIds::PSR_CLOCK), $logger])
            ->tag(InfrastructureRegistrar::LOGGER_TAG, ['channel' => InfrastructureRegistrar::LOGGER_CHANNEL]);
        $this->services->set(ServiceIds::PROBE_TOKEN_PROVIDER, DpaeTokenProvider::class)
            ->factory(GatewayFactory::class . '::probeTokenProvider')
            ->args([new Reference(ServiceIds::HTTP_TRANSPORT), new Reference(ServiceIds::PSR_CLOCK), new Reference(ServiceIds::API_CONFIGURATION), $logger])
            ->tag(InfrastructureRegistrar::LOGGER_TAG, ['channel' => InfrastructureRegistrar::LOGGER_CHANNEL]);
        $this->services->set(DpaeGateway::class, UrssafHttpGateway::class)
            ->factory(UrssafGatewayFactory::class . '::createWith')
            ->args([
                new Reference(ServiceIds::HTTP_TRANSPORT),
                new Reference(CredentialsProvider::class),
                new Reference(TokenStore::class),
                new Reference(ServiceIds::API_CONFIGURATION),
                new Reference(ServiceIds::GATEWAY_OPTIONS),
            ]);
        if ($config['gateway']['simulated']) {
            $this->services->set(DpaeGateway::class, SimulatedDpaeGateway::class)
                ->factory(SimulatedGatewayFactory::class . '::create')
                ->args([$config['declaration_mode'], new Reference(Clock::class), new Reference(IdGenerator::class)]);
            $this->services->alias(SimulatedDpaeGateway::class, DpaeGateway::class)->public();
        }
    }
}
