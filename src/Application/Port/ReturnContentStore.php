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

use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Downloaded documents, kept beyond URSSAF's three-month purge (ADR 0008). Personal data: a host protects the storage.
 */
interface ReturnContentStore
{
    public function find(TenantId $tenantId, ReturnIdentity $identity): ?ReturnContent;

    public function save(TenantId $tenantId, ReturnIdentity $identity, ReturnContent $content): void;
}
