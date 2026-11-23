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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;

final class BundleConfigurationValidationTest extends TestCase
{
    public function testTheDoctrineDriverNeedsDoctrineBundle(): void
    {
        $this->expectException(ServiceNotFoundException::class);
        $this->expectExceptionMessage('doctrine.orm.default_entity_manager');

        Harness::bootDefault(['persistence' => ['driver' => 'doctrine']]);
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidConfigurations')]
    #[DataProvider('invalidCredentials')]
    #[DataProvider('invalidWorkflows')]
    public function testRejectsAnInvalidConfiguration(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        Harness::bootDefault($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'unknown declaration mode' => [['declaration_mode' => 'production'], 'Unknown declaration mode "production": use "test" or "real"'];
        yield 'upper case mode' => [['declaration_mode' => 'REAL'], 'Unknown declaration mode'];
        yield 'unknown time zone' => [['returns_time_zone' => 'Mars/Phobos'], 'Unknown time zone'];
        yield 'empty encoding' => [['authentication_encoding' => ''], 'authentication_encoding'];
        yield 'empty content type' => [['deposit_content_type' => ''], 'deposit_content_type'];
        yield 'flow limit below 1' => [['max_flow_bytes' => 0], 'max_flow_bytes'];
        yield 'response limit below 1' => [['max_response_bytes' => 0], 'max_response_bytes'];
        yield 'timeout below 0.1' => [['http_timeout' => 0.05], 'http_timeout'];
        yield 'same indicators' => [['test_indicator' => ['real' => 'X', 'test' => 'X']], '"test_indicator.real" and "test_indicator.test" must differ'];
        yield 'lock mode' => [['lock' => ['enabled' => 'sometimes']], 'lock.enabled'];
        yield 'lock ttl below 1' => [['lock' => ['ttl' => 0.5]], 'lock.ttl'];
        yield 'empty lock factory' => [['lock' => ['factory' => '']], 'lock.factory'];
        yield 'credentials and provider' => [['credentials_provider' => 'my.provider'], 'mutually exclusive'];
        yield 'custom driver without services' => [['persistence' => ['driver' => 'custom']], 'The "custom" driver requires'];
        yield 'custom driver without the last service' => [['persistence' => ['driver' => 'custom', 'deposit_repository' => 'a', 'return_repository' => 'b', 'sync_cursor_repository' => 'c', 'return_content_store' => 'd']], 'The "custom" driver requires'];
        yield 'custom driver without the first service' => [['persistence' => ['driver' => 'custom', 'return_repository' => 'b', 'sync_cursor_repository' => 'c', 'return_content_store' => 'd', 'transaction_manager' => 'e']], 'The "custom" driver requires'];
        yield 'flows kept without doctrine' => [['persistence' => ['driver' => 'in_memory', 'keep_flows' => true]], '"persistence.keep_flows" requires the "doctrine" driver'];
        yield 'simulated gateway with the real mode' => [['declaration_mode' => 'real', 'gateway' => ['simulated' => true]], '"gateway.simulated" is meant for tests'];
        yield 'invalid scheduler frequency' => [['scheduler' => ['frequencies' => ['sync_returns' => 'often']]], 'Invalid ISO 8601 duration'];
        yield 'invalid retention' => [['outbox' => ['retention' => 'long']], 'Invalid ISO 8601 duration'];
        yield 'custom driver outbox without service' => [[
            'outbox' => ['enabled' => true],
            'persistence' => ['driver' => 'custom', 'deposit_repository' => 'a', 'return_repository' => 'b', 'sync_cursor_repository' => 'c', 'return_content_store' => 'd', 'transaction_manager' => 'e'],
        ], 'requires "outbox.service"'];
        yield 'empty tenant' => [['tenants' => ['']], 'tenants'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidWorkflows(): iterable
    {
        yield 'invalid duration' => [['workflow' => ['stale_deposit_after' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid skew' => [['workflow' => ['reconciliation_skew' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid window' => [['workflow' => ['reconciliation_window' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid review' => [['workflow' => ['manual_review_after' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid overlap' => [['workflow' => ['polling_overlap' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid lookback' => [['workflow' => ['initial_lookback' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'invalid range' => [['workflow' => ['polling_max_range' => 'soon']], 'Invalid ISO 8601 duration'];
        yield 'attempts below 1' => [['workflow' => ['max_deposit_attempts' => 0]], 'max_deposit_attempts'];
        yield 'retry delay below 1' => [['workflow' => ['polling_retry_delay_seconds' => 0]], 'polling_retry_delay_seconds'];
        yield 'refusals below 1' => [['workflow' => ['max_polling_constraint_retries' => 0]], 'max_polling_constraint_retries'];
        yield 'auto adopt' => [['workflow' => ['auto_adopt' => 'sometimes']], 'auto_adopt'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidCredentials(): iterable
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        yield 'empty siret' => [['credentials' => ['tenant-1' => ['siret' => '', 'nom' => 'x', 'prenom' => 'y', 'password' => 'z', 'service' => 25]]], 'siret'];
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        yield 'empty name' => [['credentials' => ['tenant-1' => ['siret' => 's', 'nom' => '', 'prenom' => 'y', 'password' => 'z', 'service' => 25]]], 'nom'];
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        yield 'empty first name' => [['credentials' => ['tenant-1' => ['siret' => 's', 'nom' => 'x', 'prenom' => '', 'password' => 'z', 'service' => 25]]], 'prenom'];
        yield 'empty password' => [['credentials' => ['tenant-1' => ['siret' => 's', 'nom' => 'x', 'prenom' => 'y', 'password' => '', 'service' => 25]]], 'password'];
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        yield 'missing service' => [['credentials' => ['tenant-2' => ['siret' => 's', 'nom' => 'x', 'prenom' => 'y', 'password' => 'z']]], 'service'];
    }

    public function testSomeCredentialsSourceIsRequired(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Configure either "credentials" or "credentials_provider"');

        Harness::bootDefault(['credentials' => null]);
    }

    public function testTheLimitsOfTheConfigurationAreAccepted(): void
    {
        $harness = Harness::bootDefault([
            'max_flow_bytes' => 1,
            'max_response_bytes' => 1,
            'http_timeout' => 0.1,
            'lock' => ['ttl' => 1.0],
            'workflow' => ['max_deposit_attempts' => 1, 'polling_retry_delay_seconds' => 1, 'max_polling_constraint_retries' => 1],
        ]);

        static::assertTrue($harness->has(DepositDpaeHandler::class));
    }
}
