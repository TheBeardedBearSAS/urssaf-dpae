<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Behaves like a database: what is saved and what is read are copies, so a change that is not saved is lost.
 */
final readonly class SnapshotReturnRepository implements ReturnRepository
{
    public function __construct(private ReturnRepository $store) {}

    public function find(TenantId $tenantId, ReturnIdentity $identity): ?TrackedReturn
    {
        $found = $this->store->find($tenantId, $identity);

        return $found instanceof TrackedReturn ? clone $found : null;
    }

    public function save(TrackedReturn $return): void
    {
        $this->store->save(clone $return);
    }
}
