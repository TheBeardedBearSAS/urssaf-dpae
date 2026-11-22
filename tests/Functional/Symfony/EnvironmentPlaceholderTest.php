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

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ServiceIds;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use Throwable;

/**
 * The documented configuration reads the mode, the hosts and the credentials from environment variables: they are
 * resolved at runtime (ADR 0009).
 */
final class EnvironmentPlaceholderTest extends TestCase
{
    private const VARIABLES = [
        'URSSAF_DPAE_TEST_MODE' => 'real',
        'URSSAF_DPAE_TEST_DEPOSIT_URI' => 'https://deposit.example.test',
        'URSSAF_DPAE_TEST_AUTHENTICATION_URI' => 'https://auth.example.test/authentifier_dpae',
        'URSSAF_DPAE_TEST_SIRET' => '98765432109876',
        'URSSAF_DPAE_TEST_NAME' => 'Durand',
        'URSSAF_DPAE_TEST_FIRST_NAME' => 'Paul',
        'URSSAF_DPAE_TEST_SERVICE' => '98',
        'URSSAF_DPAE_TEST_EMPTY_MODE' => '',
    ];

    public function testTheModeTheHostsAndTheCredentialsMayComeFromEnvironmentVariables(): void
    {
        $this->withVariables(static function (): void {
            $harness = Harness::bootDefault([
                'declaration_mode' => '%env(URSSAF_DPAE_TEST_MODE)%',
                'hosts' => [
                    'deposit' => '%env(default::URSSAF_DPAE_TEST_DEPOSIT_URI)%',
                    'authentication' => '%env(default::URSSAF_DPAE_TEST_AUTHENTICATION_URI)%',
                    'download' => '%env(default::URSSAF_DPAE_TEST_UNSET_URI)%',
                ],
                'credentials' => ['tenant-1' => [
                    'siret' => '%env(URSSAF_DPAE_TEST_SIRET)%',
                    'nom' => '%env(URSSAF_DPAE_TEST_NAME)%',
                    'prenom' => '%env(URSSAF_DPAE_TEST_FIRST_NAME)%',
                    // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
                    'password' => 'Passw0rd-fake',
                    'service' => '%env(int:URSSAF_DPAE_TEST_SERVICE)%',
                ]],
            ]);
            $configuration = $harness->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);
            $credentials = $harness->privateService(CredentialsProvider::class, CredentialsProvider::class)->forTenant(TenantId::fromString('tenant-1'));
            $service = ServiceCode::concentrator();

            static::assertSame(DeclarationMode::Real, $configuration->declarationMode());
            static::assertSame('https://deposit.example.test', $configuration->endpoints()->baseUri(DpaeService::Deposit, $service));
            static::assertSame('https://auth.example.test/authentifier_dpae', $configuration->endpoints()->baseUri(DpaeService::Authentication, $service));
            static::assertSame('https://telechargement.dpae-edi.urssaf.fr:8443', $configuration->endpoints()->baseUri(DpaeService::Download, $service));
            static::assertSame('98765432109876', $credentials->siret()->value());
            static::assertSame('Durand', $credentials->nom());
            static::assertSame('Paul', $credentials->prenom());
            static::assertSame(98, $credentials->service()->value());
        });
    }

    public function testAnEmptyDeclarationModeVariableIsRefusedAtRuntimeWithAClearMessage(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The "urssaf_dpae.declaration_mode" option is empty');

        $this->withVariables(static function (): void {
            Harness::bootDefault(['declaration_mode' => '%env(URSSAF_DPAE_TEST_EMPTY_MODE)%'])->privateService(ServiceIds::API_CONFIGURATION, ApiConfiguration::class);
        });
    }

    public function testATenantWhoseVariablesAreMissingBreaksOnlyThatTenant(): void
    {
        $this->withVariables(static function (): void {
            $harness = Harness::bootDefault([
                'credentials' => [
                    'broken' => self::declarant('%env(URSSAF_DPAE_TEST_UNSET_SIRET)%'),
                    'invalid' => self::declarant('not-a-siret'),
                    'healthy' => self::declarant('%env(URSSAF_DPAE_TEST_SIRET)%'),
                ],
            ]);
            $provider = $harness->privateService(CredentialsProvider::class, CredentialsProvider::class);

            static::assertSame('98765432109876', $provider->forTenant(TenantId::fromString('healthy'))->siret()->value());
            foreach (['broken', 'invalid'] as $tenant) {
                try {
                    $provider->forTenant(TenantId::fromString($tenant));
                    static::fail('The tenant "' . $tenant . '" must fail.');
                } catch (Throwable) {
                    static::assertSame('98765432109876', $provider->forTenant(TenantId::fromString('healthy'))->siret()->value());
                }
            }
        });
    }

    /**
     * @return array{siret: string, nom: string, prenom: string, password: string, service: int}
     */
    private static function declarant(string $siret): array
    {
        // @mago-expect lint:no-literal-password Fake credentials of a test configuration.
        return ['siret' => $siret, 'nom' => 'Durand', 'prenom' => 'Paul', 'password' => 'Passw0rd-fake', 'service' => 25];
    }

    /**
     * @param callable(): void $test
     * @param-immediately-invoked-callable $test
     */
    private function withVariables(callable $test): void
    {
        foreach (self::VARIABLES as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
        try {
            $test();
        } finally {
            foreach (array_keys(self::VARIABLES) as $name) {
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }
    }
}
