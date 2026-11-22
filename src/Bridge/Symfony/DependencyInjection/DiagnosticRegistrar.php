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

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Command\CheckInstallationCommand;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\AuthenticationCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\ConfigurationCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CredentialsCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\DatabaseSchemaCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\InstallationCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\LockCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\SimulatedGatewayCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\TokenStoreCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;

/**
 * The "urssaf:dpae:check" command and its checks, in report order (registration order, host checks afterwards unless they
 * have a positive tag priority).
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final readonly class DiagnosticRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param Config $config
     */
    public function register(array $config): void
    {
        $this->check(ConfigurationCheck::class, [new Reference(ServiceIds::API_CONFIGURATION), new Reference(CredentialsProvider::class)]);
        $this->check(CredentialsCheck::class, [new Reference(CredentialsProvider::class)]);
        [$gatewayCheck, $gatewayArguments] = $config['gateway']['simulated']
            ? [SimulatedGatewayCheck::class, []]
            : [AuthenticationCheck::class, [new Reference(CredentialsProvider::class), new Reference(ServiceIds::PROBE_TOKEN_PROVIDER)]];
        $this->check($gatewayCheck, $gatewayArguments);
        $this->check(TokenStoreCheck::class, [$config['token_store']['service']]);
        if ($config['persistence']['driver'] === BundleConfiguration::DRIVER_DOCTRINE) {
            $entityManager = $config['persistence']['entity_manager'];
            $this->check(DatabaseSchemaCheck::class, [new Reference(PersistenceRegistrar::entityManagerId($entityManager)), $entityManager]);
        }
        $this->check(LockCheck::class, [new Reference(OperationLocks::class)]);

        $this->services->set(CheckInstallationCommand::class)
            ->args([new TaggedIteratorArgument(InstallationCheck::TAG), '%' . UrssafDpaeBundle::PARAMETER_TENANTS . '%'])
            ->tag('console.command');
    }

    /**
     * @param class-string<InstallationCheck> $class
     * @param list<mixed>                     $arguments
     */
    private function check(string $class, array $arguments): void
    {
        $this->services->set($class)->args($arguments)->tag(InstallationCheck::TAG);
    }
}
