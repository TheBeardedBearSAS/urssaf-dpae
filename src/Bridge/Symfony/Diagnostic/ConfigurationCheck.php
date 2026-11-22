<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic;

use Override;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;

/**
 * Reports the declaration mode of the installation and, per tenant, the four resolved base URIs (ADR 0009). No secret:
 * the URIs carry no credentials (refused when configured).
 */
final readonly class ConfigurationCheck implements InstallationCheck
{
    public function __construct(
        private ApiConfiguration $configuration,
        private CredentialsProvider $credentials,
    ) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        $mode = $this->configuration->declarationMode();
        $results = [$mode === DeclarationMode::Real
            ? new CheckResult('declaration mode', '', CheckStatus::Warning, 'real: this installation may deposit real declarations, which have legal effects.')
            : new CheckResult('declaration mode', '', CheckStatus::Ok, 'test: real declarations are refused.')];
        foreach ($context->tenants as $tenantId) {
            $result = $this->hosts($tenantId);
            if ($result instanceof CheckResult) {
                $results[] = $result;
            }
        }

        return $results;
    }

    private function hosts(TenantId $tenantId): ?CheckResult
    {
        try {
            $service = $this->credentials->forTenant($tenantId)->service();
        } catch (UrssafException) {
            // Reported by the credentials check.
            return null;
        }
        $uris = [];
        foreach (DpaeService::cases() as $dpaeService) {
            $uris[] = \sprintf('%s=%s', $dpaeService->value, $this->configuration->endpoints()->baseUri($dpaeService, $service));
        }

        return new CheckResult('hosts', $tenantId->value(), CheckStatus::Ok, implode(' ', $uris));
    }
}
