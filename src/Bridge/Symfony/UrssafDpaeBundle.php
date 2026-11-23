<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\BundleConfiguration;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ServiceRegistrar;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\InstallationCheck;

/**
 * Symfony integration of the URSSAF "API DPAE" client (Symfony 6.4, 7.x and 8.x).
 *
 * @phpstan-import-type Config from ServiceRegistrar
 */
final class UrssafDpaeBundle extends AbstractBundle
{
    public const ALIAS = 'urssaf_dpae';
    public const PARAMETER_TENANTS = 'urssaf_dpae.tenants';

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle directory ({@see AbstractBundle} assumes a class one level below it), so that the framework finds the
     * shipped translations in Resources/translations.
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        BundleConfiguration::define($definition);
    }

    /**
     * Maps the aggregates with Doctrine when the "doctrine" persistence driver is selected (the default when the ORM is
     * installed). Without DoctrineBundle the prepended configuration is ignored by the container.
     */
    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $persistence = self::selectedPersistence($container->getExtensionConfig(self::ALIAS));
        if ($persistence['driver'] !== BundleConfiguration::DRIVER_DOCTRINE) {
            return;
        }
        $container->prependExtensionConfig('doctrine', $persistence['entity_manager'] === 'default'
            ? DoctrineIntegration::doctrineBundleConfiguration()
            : DoctrineIntegration::doctrineBundleConfigurationFor($persistence['entity_manager']));
    }

    /**
     * @param array<array-key, mixed> $config the configuration validated by {@see BundleConfiguration}
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        /** @var Config $validated */
        $validated = $config;
        $tenants = $validated['tenants'] !== [] ? $validated['tenants'] : array_map(strval(...), array_keys($validated['credentials']));
        $configurator->parameters()->set(self::PARAMETER_TENANTS, $tenants);
        (new ServiceRegistrar($configurator->services()))->register($validated);
        $container->registerForAutoconfiguration(InstallationCheck::class)->addTag(InstallationCheck::TAG);
    }

    /**
     * @param array<array-key, array<array-key, mixed>> $configs raw configurations of the bundle, in merge order
     *
     * @return array{driver: string, entity_manager: non-empty-string}
     */
    private static function selectedPersistence(array $configs): array
    {
        /** @var array{driver: string, entity_manager: non-empty-string} $selected */
        $selected = ['driver' => BundleConfiguration::defaultDriver(), 'entity_manager' => 'default'];
        foreach ($configs as $config) {
            $persistence = \is_array($config['persistence'] ?? null) ? $config['persistence'] : [];
            $selected['driver'] = self::stringOr($persistence['driver'] ?? null, $selected['driver']);
            $selected['entity_manager'] = self::nameOr($persistence['entity_manager'] ?? null, $selected['entity_manager']);
        }

        return $selected;
    }

    /**
     * @param non-empty-string $default
     *
     * @return non-empty-string
     */
    private static function nameOr(mixed $value, string $default): string
    {
        return \is_string($value) && $value !== '' ? $value : $default;
    }

    private static function stringOr(mixed $value, string $default): string
    {
        return \is_string($value) ? $value : $default;
    }
}
