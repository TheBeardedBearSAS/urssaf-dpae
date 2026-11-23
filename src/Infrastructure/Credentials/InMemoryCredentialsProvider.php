<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Credentials;

use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Credentials provider backed by a map of tenant identifier to credentials (configuration or tests). The credentials
 * are not serializable and mask the secrets in `var_dump()`; whatever fills the map must keep the password encrypted
 * at rest.
 */
final readonly class InMemoryCredentialsProvider implements CredentialsProvider
{
    /**
     * @param array<string, DeclarantCredentials> $credentialsByTenant keyed by tenant identifier
     */
    public function __construct(private array $credentialsByTenant) {}

    /**
     * @throws RecordNotFound `credentials.not_found`
     */
    public function forTenant(TenantId $tenantId): DeclarantCredentials
    {
        return $this->credentialsByTenant[$tenantId->value()]
            ?? throw RecordNotFound::withIdentifier('credentials', $tenantId->value());
    }
}
