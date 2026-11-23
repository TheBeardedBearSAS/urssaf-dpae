<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Credentials;

use Closure;
use Override;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Credentials of the configuration, built on demand tenant by tenant: the `%env()%` placeholders of a tenant are resolved
 * (and its values validated) when that tenant is used, so a missing variable or an invalid value breaks that tenant
 * only, never the others nor the boot of the application.
 */
final readonly class LazyCredentialsProvider implements CredentialsProvider
{
    /**
     * @param array<string, Closure(): DeclarantCredentials> $factories keyed by tenant identifier
     */
    public function __construct(private array $factories) {}

    /**
     * @throws RecordNotFound `credentials.not_found`
     */
    #[Override]
    public function forTenant(TenantId $tenantId): DeclarantCredentials
    {
        $factory = $this->factories[$tenantId->value()] ?? throw RecordNotFound::withIdentifier('credentials', $tenantId->value());

        return $factory();
    }
}
