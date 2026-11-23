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

use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Process-local tracked returns, unique per `(tenant, flux.id, nature, ops)`; an absent `ops` differs from an empty one.
 */
final class InMemoryReturnRepository implements ReturnRepository
{
    /**
     * @var array<string, TrackedReturn>
     */
    private array $returns = [];

    public function find(TenantId $tenantId, ReturnIdentity $identity): ?TrackedReturn
    {
        return $this->returns[self::key($tenantId, $identity)] ?? null;
    }

    public function save(TrackedReturn $return): void
    {
        $this->returns[self::key($return->tenantId(), $return->identity())] = $return;
    }

    /**
     * Serialized, never concatenated: tenant and flow ids accept any character, and a null `ops` is not an empty one.
     */
    private static function key(TenantId $tenantId, ReturnIdentity $identity): string
    {
        return serialize([$tenantId->value(), $identity->flowId()->value(), $identity->natureCode(), $identity->ops()]);
    }
}
