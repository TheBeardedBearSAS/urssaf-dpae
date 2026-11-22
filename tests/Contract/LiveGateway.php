<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Contract;

use Nyholm\Psr7\Factory\Psr17Factory;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory;

/**
 * Wires the real HTTP gateway against the live URSSAF hosts from the environment, in **test mode only**: the
 * configuration is `ApiConfiguration::defaults()` (ceiling `DeclarationMode::Test`), so a `real` flow is refused before
 * any call (ADR 0003). There is no way to configure a real declaration here.
 *
 * - Required, otherwise the suite is skipped (all unset) or fails (partly set or invalid): `URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`, `URSSAF_DPAE_PRENOM`,
 *   `URSSAF_DPAE_PASSWORD`, `URSSAF_DPAE_SERVICE` (an integer: `25`, or `98` for a registered concentrator).
 * - Optional host overrides (ADR 0002, 0009): `URSSAF_DPAE_AUTHENTICATION_URI`, `URSSAF_DPAE_DEPOSIT_URI`,
 *   `URSSAF_DPAE_CONSULTATION_URI`, `URSSAF_DPAE_DOWNLOAD_URI`.
 * - Optional opt-in of the one call that writes: `URSSAF_DPAE_CONTRACT_DEPOSIT=1` (see {@see LiveContractTest}).
 */
final readonly class LiveGateway
{
    public const TENANT = 'contract-tests';

    private function __construct(
        public DpaeGateway $gateway,
        public ApiConfiguration $configuration,
        public TenantId $tenantId,
        public ServiceCode $service,
    ) {}

    /**
     * Null when none of the required environment variables is set (opt-in, the suite is skipped). A partial or invalid
     * configuration (a missing variable among others, a `URSSAF_DPAE_SERVICE` that is not an integer) is a failure, never
     * a skip: a misleading green run would hide the mistake.
     *
     * @throws RuntimeException
     */
    public static function fromEnvironment(): ?self
    {
        $siret = self::env('URSSAF_DPAE_SIRET');
        $name = self::env('URSSAF_DPAE_NOM');
        $firstName = self::env('URSSAF_DPAE_PRENOM');
        $password = self::env('URSSAF_DPAE_PASSWORD');
        $service = self::env('URSSAF_DPAE_SERVICE');
        $values = [$siret, $name, $firstName, $password, $service];
        if ($values === [null, null, null, null, null]) {
            return null;
        }
        if ($siret === null || $name === null || $firstName === null || $password === null || $service === null) {
            throw new RuntimeException('Incomplete URSSAF contract configuration: URSSAF_DPAE_SIRET, _NOM, _PRENOM, _PASSWORD and _SERVICE must all be set (and non-empty), or none.');
        }
        if (filter_var($service, \FILTER_VALIDATE_INT) === false) {
            throw new RuntimeException('URSSAF_DPAE_SERVICE must be an integer (25, or 98 for a registered concentrator).');
        }

        $serviceCode = ServiceCode::fromInt((int) $service);
        $tenantId = TenantId::fromString(self::TENANT);
        $credentials = new InMemoryCredentialsProvider([
            $tenantId->value() => DeclarantCredentials::of(Siret::fromString($siret), $name, $firstName, $password, $serviceCode),
        ]);

        $configuration = ApiConfiguration::defaults()->withEndpoints(self::endpoints());
        $factory = new Psr17Factory();
        $client = new SampleRecorder(new Psr18Client(HttpClient::create(['timeout' => 30, 'max_redirects' => 0]), $factory, $factory), $factory);

        return new self(
            UrssafGatewayFactory::create(new HttpTransport($client, $factory, $factory), $credentials, new InMemoryTokenStore(), $configuration),
            $configuration,
            $tenantId,
            $serviceCode,
        );
    }

    public static function env(string $name): ?string
    {
        $value = getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function endpoints(): ServiceEndpoints
    {
        $endpoints = ServiceEndpoints::defaults();
        $overrides = [
            'URSSAF_DPAE_AUTHENTICATION_URI' => DpaeService::Authentication,
            'URSSAF_DPAE_DEPOSIT_URI' => DpaeService::Deposit,
            'URSSAF_DPAE_CONSULTATION_URI' => DpaeService::Consultation,
            'URSSAF_DPAE_DOWNLOAD_URI' => DpaeService::Download,
        ];
        foreach ($overrides as $variable => $service) {
            $uri = self::env($variable);
            if ($uri !== null) {
                $endpoints = $endpoints->withBaseUri($service, $uri);
            }
        }

        return $endpoints;
    }
}
