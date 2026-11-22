<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port;

use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Source of the credentials of a tenant. The password travels in clear to URSSAF, so an implementation that stores it
 * must encrypt it (api-reference.md section 2.1); the package never stores it.
 */
interface CredentialsProvider
{
    /**
     * @throws RecordNotFound `credentials.not_found` when the tenant has no credentials
     */
    public function forTenant(TenantId $tenantId): DeclarantCredentials;
}
