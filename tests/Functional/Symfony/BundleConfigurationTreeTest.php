<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle;

/**
 * The configuration tree on its own: every default of the documented options.
 */
final class BundleConfigurationTreeTest extends TestCase
{
    public function testTheDefaultsAreTheDocumentedOnes(): void
    {
        $processed = self::process(['credentials_provider' => 'my.provider']);

        static::assertEqualsCanonicalizing([
            'declaration_mode' => 'test',
            'hosts' => ['authentication' => null, 'deposit' => null, 'consultation' => null, 'download' => null],
            'concentrator_port' => 8443,
            'authentication_encoding' => 'UTF-8',
            'deposit_content_type' => 'text/plain',
            'max_flow_bytes' => 100_000_000,
            'max_response_bytes' => 100_000_000,
            'returns_time_zone' => 'Europe/Paris',
            'test_indicator' => ['real' => '120', 'test' => 'TST'],
            'http_client' => null,
            'http_timeout' => 30.0,
            'credentials_provider' => 'my.provider',
            'credentials' => [],
            'tenants' => [],
            'token_store' => ['service' => null],
            'gateway' => ['simulated' => false],
            'lock' => ['enabled' => 'auto', 'factory' => 'lock.factory', 'ttl' => 3600.0],
            'outbox' => ['enabled' => false, 'service' => null, 'sink' => 'messenger', 'bus' => 'messenger.default_bus', 'retention' => 'P30D'],
            'scheduler' => [
                'enabled' => false,
                'frequencies' => ['sync_returns' => 'PT15M', 'reconcile' => 'PT15M', 'relay_outbox' => 'PT1M', 'purge_outbox' => 'P1D'],
            ],
            'workflow' => [
                'max_deposit_attempts' => 3,
                'stale_deposit_after' => 'PT15M',
                'reconciliation_skew' => 'PT5M',
                'reconciliation_window' => 'PT1H',
                'manual_review_after' => 'PT6H',
                'polling_overlap' => 'PT2H',
                'initial_lookback' => 'P7D',
                'polling_max_range' => null,
                'polling_retry_delay_seconds' => 60,
                'max_polling_constraint_retries' => 5,
                'auto_adopt' => null,
            ],
            'persistence' => [
                'driver' => 'doctrine',
                'deposit_repository' => null,
                'return_repository' => null,
                'sync_cursor_repository' => null,
                'return_content_store' => null,
                'transaction_manager' => null,
                'entity_manager' => 'default',
                'keep_flows' => false,
            ],
            'messenger' => ['enabled' => false],
        ], $processed);
    }

    public function testTheAcceptedValuesOfTheEnumerations(): void
    {
        foreach (['auto', true, false] as $lock) {
            static::assertSame($lock, self::section(self::process(['credentials_provider' => 'p', 'lock' => ['enabled' => $lock]]), 'lock')['enabled']);
        }
        foreach ([null, true, false] as $autoAdopt) {
            $workflow = self::section(self::process(['credentials_provider' => 'p', 'workflow' => ['auto_adopt' => $autoAdopt]]), 'workflow');
            static::assertArrayHasKey('auto_adopt', $workflow);
            static::assertSame($autoAdopt, $workflow['auto_adopt']);
        }
        foreach (['in_memory', 'doctrine'] as $driver) {
            static::assertSame($driver, self::section(self::process(['credentials_provider' => 'p', 'persistence' => ['driver' => $driver]]), 'persistence')['driver']);
        }
    }

    public function testTheCredentialsAreKeyedByTheTenantAsGiven(): void
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        $processed = self::process(['credentials' => ['42' => ['siret' => 's', 'nom' => 'n', 'prenom' => 'p', 'password' => 'w', 'service' => 97]]]);

        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        static::assertSame(['42' => ['siret' => 's', 'nom' => 'n', 'prenom' => 'p', 'password' => 'w', 'service' => 97]], $processed['credentials']);
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $name): array
    {
        // @mago-expect analysis:mixed-assignment The processed configuration is untyped; the assertion below narrows it.
        $section = $config[$name];
        static::assertIsArray($section);

        return $section;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function process(array $config): array
    {
        $extension = (new UrssafDpaeBundle())->getContainerExtension();
        static::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        static::assertInstanceOf(ConfigurationInterface::class, $configuration);

        // @mago-expect lint:inline-variable-return The variable carries the key type that the processor loses.
        /** @var array<string, mixed> $processed */
        $processed = (new Processor())->processConfiguration($configuration, [$config]);

        return $processed;
    }
}
