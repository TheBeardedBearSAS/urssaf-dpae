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

use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Persistence of tracked returns, unique per `(tenant, flux.id, nature, ops)` (ADR 0006, 0008): an absent `ops` differs
 * from an empty one in this port, the storage maps it as it needs.
 */
interface ReturnRepository
{
    public function find(TenantId $tenantId, ReturnIdentity $identity): ?TrackedReturn;

    /**
     * Inserts or updates the return under its identity.
     */
    public function save(TrackedReturn $return): void;
}
