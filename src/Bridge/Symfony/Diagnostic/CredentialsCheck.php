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
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Every tenant has valid credentials in the configured provider (nothing is sent to URSSAF).
 */
final readonly class CredentialsCheck implements InstallationCheck
{
    private const NAME = 'credentials';

    public function __construct(private CredentialsProvider $credentials) {}

    #[Override]
    public function run(CheckContext $context): array
    {
        return array_map($this->check(...), $context->tenants);
    }

    private function check(TenantId $tenantId): CheckResult
    {
        try {
            $this->credentials->forTenant($tenantId);
        } catch (UrssafException $failure) {
            return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Failure, $failure->getMessage());
        }

        return new CheckResult(self::NAME, $tenantId->value(), CheckStatus::Ok, 'Credentials found.');
    }
}
