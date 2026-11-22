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

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

/**
 * Configuration tree of the "urssaf_dpae" bundle configuration.
 */
final class BundleConfiguration
{
    public const DRIVER_IN_MEMORY = 'in_memory';
    public const DRIVER_DOCTRINE = 'doctrine';
    public const DRIVER_CUSTOM = 'custom';

    public static function defaultDriver(): string
    {
        return interface_exists(EntityManagerInterface::class) ? self::DRIVER_DOCTRINE : self::DRIVER_IN_MEMORY;
    }

    public static function define(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->validate()
                ->ifTrue(ConfigurationRules::hasBothCredentialSources(...))
                ->thenInvalid('"credentials" and "credentials_provider" are mutually exclusive.')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::hasNoCredentialSource(...))
                ->thenInvalid('Configure either "credentials" or "credentials_provider".')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::lockEnabledWithoutComponent(...))
                ->thenInvalid('"lock.enabled: true" requires symfony/lock (composer require symfony/lock).')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::simulatedGatewayInRealMode(...))
                ->thenInvalid('"gateway.simulated" is meant for tests and cannot be used with "declaration_mode: real".')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::outboxWithoutStorage(...))
                ->thenInvalid('The "custom" persistence driver requires "outbox.service" when the outbox is enabled.')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::schedulerEnabledWithoutComponent(...))
                ->thenInvalid('"scheduler.enabled" requires symfony/scheduler (composer require symfony/scheduler).')
            ->end()
            ->children()
                ->scalarNode('declaration_mode')
                    ->defaultValue('test')
                    ->info('The highest mode this installation may deposit, "test" or "real"; usually "%env(URSSAF_DPAE_DECLARATION_MODE)%" (resolved at runtime). Never "real" by default.')
                    ->validate()
                        ->ifTrue(ConfigurationRules::isUnknownDeclarationMode(...))
                        ->thenInvalid('Unknown declaration mode %s: use "test" or "real".')
                    ->end()
                ->end()
                ->append(self::hosts())
                ->integerNode('concentrator_port')->defaultValue(ServiceEndpoints::DEFAULT_CONCENTRATOR_PORT)->info('Port added to the default deposit, consultation and download URIs of a concentrator (service code 98); never to an overridden URI.')->end()
                ->scalarNode('authentication_encoding')->defaultValue(IdentifiantsXmlWriter::DEFAULT_ENCODING)->cannotBeEmpty()->info('XML encoding of the "identifiants" body (not published by URSSAF).')->end()
                ->scalarNode('deposit_content_type')->defaultValue(ApiConfiguration::DEFAULT_DEPOSIT_CONTENT_TYPE)->cannotBeEmpty()->info('Content-Type of the deposit request.')->end()
                ->integerNode('max_flow_bytes')->min(1)->defaultValue(ApiConfiguration::DEFAULT_MAX_FLOW_BYTES)->info('Local limit of the uncompressed flow.')->end()
                ->integerNode('max_response_bytes')->min(1)->defaultValue(ApiConfiguration::DEFAULT_MAX_RESPONSE_BYTES)->info('Limit of a decoded answer (against decompression bombs).')->end()
                ->scalarNode('returns_time_zone')
                    ->defaultValue(WireDate::DEFAULT_TIME_ZONE)
                    ->info('Time zone of the dates of the list paths and of "publication" / "production".')
                    ->validate()
                        ->ifTrue(ConfigurationRules::isUnknownTimeZone(...))
                        ->thenInvalid('Unknown time zone %s.')
                    ->end()
                ->end()
                ->arrayNode('test_indicator')
                    ->info('Value of FR_DUE_Upload.Test.Indicator for each deposit mode: inferred from the C_VERSION rule, not published.')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(ConfigurationRules::indicatorsNotDistinct(...))
                        ->thenInvalid('"test_indicator.real" and "test_indicator.test" must differ.')
                    ->end()
                    ->children()
                        ->scalarNode('real')->defaultValue(TestIndicators::defaults()->forMode(DeclarationMode::Real))->end()
                        ->scalarNode('test')->defaultValue(TestIndicators::defaults()->forMode(DeclarationMode::Test))->end()
                    ->end()
                ->end()
                ->scalarNode('http_client')->defaultNull()->info('Service id of a PSR-18 client that does not follow redirects (default: a dedicated Symfony HttpClient with max_redirects 0).')->end()
                ->floatNode('http_timeout')->min(0.1)->defaultValue(30.0)->info('Timeout in seconds of the default HTTP client.')->end()
                ->scalarNode('credentials_provider')->defaultNull()->info('Service id of a CredentialsProvider.')->end()
                ->arrayNode('credentials')
                    ->info('Credentials by tenant id; use "%env(...)%" placeholders.')
                    ->useAttributeAsKey('tenant_id')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('siret')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('nom')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('prenom')->isRequired()->cannotBeEmpty()->end()
                            ->scalarNode('password')->isRequired()->cannotBeEmpty()->end()
                            ->integerNode('service')->isRequired()->info('Service code: 25 (direct declarant), 98 (concentrator), or any other integer, which is kept as is; usually "%env(int:URSSAF_DPAE_SERVICE)%".')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('tenants')
                    ->info('Tenants processed by commands that cannot discover them (default: the keys of "credentials").')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('token_store')
                    ->info('The token is kept in memory, per process (ADR 0001); a host may provide its own store.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('service')->defaultNull()->info('Service id of a TokenStore.')->end()
                    ->end()
                ->end()
                ->arrayNode('gateway')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('simulated')->defaultFalse()->info('Tests only: replaces the URSSAF API with the in-memory Testing\\SimulatedDpaeGateway (public service, to script the scenarios). Refused with the real mode.')->end()
                    ->end()
                ->end()
                ->arrayNode('lock')
                    ->info('Non-blocking Symfony Lock per command / handler and tenant ("urssaf_dpae.<operation>.<scope>"): overlapping runs are skipped (command) or retried later (Messenger).')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('enabled')->values(['auto', true, false])->defaultValue('auto')->info('"auto": enabled when symfony/lock is installed and the factory service exists.')->end()
                        ->scalarNode('factory')->defaultValue('lock.factory')->cannotBeEmpty()->info('Service id of a Symfony\\Component\\Lock\\LockFactory.')->end()
                        ->floatNode('ttl')->min(1)->defaultValue(3600.0)->info('Seconds after which a lock of a crashed process expires.')->end()
                    ->end()
                ->end()
                ->append(self::outbox())
                ->append(self::scheduler())
                ->append(self::workflow())
                ->append(self::persistence())
                ->arrayNode('messenger')
                    ->info('Registers the handlers of the Messenger messages of the package.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end();
    }

    private static function hosts(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('hosts');
        $node->info('Base URI of each service (NAT/5492 defaults); every one can be overridden, the authentication one included, e.g. "%env(default::URSSAF_DPAE_DEPOSIT_URI)%". A path prefix is kept.')->addDefaultsIfNotSet();
        $children = $node->children();
        foreach (DpaeService::cases() as $service) {
            $children->scalarNode($service->value)->defaultNull()->info(\sprintf('Default: %s.', $service->defaultBaseUri()))->end();
        }

        return $node;
    }

    private static function outbox(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('outbox');
        $node
            ->info('Integration events (Contract\\Event) written in the transaction of each state change, then relayed to the host (urssaf:dpae:relay-outbox).')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('service')->defaultNull()->info('Service id of your Outbox (default: the outbox of the persistence driver).')->end()
                ->scalarNode('sink')->defaultValue('messenger')->cannotBeEmpty()->info('"messenger" (bus below), "event_dispatcher", or the service id of an IntegrationEventSink.')->end()
                ->scalarNode('bus')->defaultValue('messenger.default_bus')->cannotBeEmpty()->info('Bus of the "messenger" sink.')->end()
                ->scalarNode('retention')->defaultValue('P30D')->info('How long a dispatched entry is kept (urssaf:dpae:purge-outbox).')->validate()->ifTrue(ConfigurationRules::isInvalidInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end()->end()
            ->end();

        return $node;
    }

    private static function scheduler(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('scheduler');
        $node
            ->info('Symfony Scheduler schedule "urssaf_dpae" running the crons of the package (RunCommandMessage): an alternative to the crontab.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->arrayNode('frequencies')
                    ->addDefaultsIfNotSet()
                    ->append(self::frequency('sync_returns', 'PT15M', 'urssaf:dpae:sync-returns'))
                    ->append(self::frequency('reconcile', 'PT15M', 'urssaf:dpae:reconcile'))
                    ->append(self::frequency('relay_outbox', 'PT1M', 'urssaf:dpae:relay-outbox (only when the outbox is enabled)'))
                    ->append(self::frequency('purge_outbox', 'P1D', 'urssaf:dpae:purge-outbox (only when the outbox is enabled)'))
                ->end()
            ->end();

        return $node;
    }

    private static function frequency(string $task, string $default, string $commands): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($task);
        $node
            ->defaultValue($default)
            ->info(\sprintf('ISO 8601 duration between two runs of %s; null to leave the task out.', $commands))
            ->validate()->ifTrue(ConfigurationRules::isInvalidOptionalInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end();

        return $node;
    }

    private static function workflow(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('workflow');
        $node
            ->info('Tunables of the workflow (Application\\Policy\\WorkflowPolicy): package defaults, not specification values.')
            ->addDefaultsIfNotSet()
            ->children()
                ->integerNode('max_deposit_attempts')->min(1)->defaultValue(3)->info('Claims after which a deposit that never reached URSSAF is "failed".')->end()
                ->append(self::interval('stale_deposit_after', 'PT15M', 'A "depositing" deposit older than this is an unknown outcome.'))
                ->append(self::interval('reconciliation_skew', 'PT5M', 'How long before the claim a flux may be published (clock skew, time zone).'))
                ->append(self::interval('reconciliation_window', 'PT1H', 'How long after the claim a flux may be published.'))
                ->append(self::interval('manual_review_after', 'PT6H', 'Age of the claim after which an unreconciled deposit needs a human.'))
                ->append(self::interval('polling_overlap', 'PT2H', 'Margin kept before the instant a listing started (unpublished time zone, clock skew): keep at least PT1H.'))
                ->append(self::interval('initial_lookback', 'P7D', 'How far back the first synchronisation of a tenant looks (capped at 13 months).'))
                ->scalarNode('polling_max_range')->defaultNull()->info('Longest range of one listing (ISO 8601 duration), null while the limit of URSSAF is unknown.')->validate()->ifTrue(ConfigurationRules::isInvalidOptionalInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end()->end()
                ->integerNode('polling_retry_delay_seconds')->min(1)->defaultValue(60)->info('Delay advised after a polling refusal that carried no Retry-After.')->end()
                ->integerNode('max_polling_constraint_retries')->min(1)->defaultValue(5)->info('Consecutive refusals after which a tenant needs attention.')->end()
                ->enumNode('auto_adopt')->values([null, true, false])->defaultNull()->info('Whether the reconciliation may give a deposit the flux it found: null decides per deposit (test mode, known declarant), true or false forces it.')->end()
            ->end();

        return $node;
    }

    private static function interval(string $name, string $default, string $info): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node
            ->defaultValue($default)
            ->info($info)
            ->validate()->ifTrue(ConfigurationRules::isInvalidInterval(...))->thenInvalid('Invalid ISO 8601 duration %s.')->end();

        return $node;
    }

    private static function persistence(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('persistence');
        $node
            ->addDefaultsIfNotSet()
            ->validate()
                ->ifTrue(ConfigurationRules::customDriverWithoutServices(...))
                ->thenInvalid('The "custom" driver requires "deposit_repository", "return_repository", "sync_cursor_repository", "return_content_store" and "transaction_manager".')
            ->end()
            ->validate()
                ->ifTrue(ConfigurationRules::keepFlowsWithoutDoctrine(...))
                ->thenInvalid('"persistence.keep_flows" requires the "doctrine" driver.')
            ->end()
            ->children()
                ->enumNode('driver')->values([self::DRIVER_IN_MEMORY, self::DRIVER_DOCTRINE, self::DRIVER_CUSTOM])->defaultValue(self::defaultDriver())->end()
                ->scalarNode('deposit_repository')->defaultNull()->info('Service id of your DepositRepository (driver "custom").')->end()
                ->scalarNode('return_repository')->defaultNull()->info('Service id of your ReturnRepository (driver "custom").')->end()
                ->scalarNode('sync_cursor_repository')->defaultNull()->info('Service id of your SyncCursorRepository (driver "custom").')->end()
                ->scalarNode('return_content_store')->defaultNull()->info('Service id of your ReturnContentStore (driver "custom").')->end()
                ->scalarNode('transaction_manager')->defaultNull()->info('Service id of your TransactionManager (driver "custom").')->end()
                ->scalarNode('entity_manager')->defaultValue('default')->cannotBeEmpty()
                    ->info('Name of the Doctrine entity manager. Use a DEDICATED entity manager (own connection) for this package: its repositories share the transaction of the entity manager they use.')
                ->end()
                ->booleanNode('keep_flows')->defaultFalse()->info('Doctrine driver: exposes the public service DoctrineDepositFlowStore, to keep an exact copy of the deposited flows (personal data, docs/doctrine.md). The package never calls it.')->end()
            ->end();

        return $node;
    }
}
