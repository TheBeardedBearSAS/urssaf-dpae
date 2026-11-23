<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory;

use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Process-local synchronisation cursors, one per tenant.
 */
final class InMemorySyncCursorRepository implements SyncCursorRepository
{
    /**
     * @var array<string, SyncCursor>
     */
    private array $cursors = [];

    public function find(TenantId $tenantId): ?SyncCursor
    {
        return $this->cursors[$tenantId->value()] ?? null;
    }

    public function save(SyncCursor $cursor): void
    {
        $this->cursors[$cursor->tenantId()->value()] = $cursor;
    }
}
