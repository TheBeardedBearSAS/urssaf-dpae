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

use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Persistence of the per-tenant synchronisation cursor, moved only by `SynchronizeReturns` (ADR 0006).
 * (ADR 0008 names it `SynchronizationCursorRepository`; the shorter name is used, see the ADR 0008 amendment.)
 */
interface SyncCursorRepository
{
    public function find(TenantId $tenantId): ?SyncCursor;

    public function save(SyncCursor $cursor): void;
}
