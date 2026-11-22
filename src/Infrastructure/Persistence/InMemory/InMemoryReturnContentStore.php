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

use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Process-local downloaded documents (personal data held in memory only).
 */
final class InMemoryReturnContentStore implements ReturnContentStore
{
    /**
     * @var array<string, ReturnContent>
     */
    private array $contents = [];

    public function find(TenantId $tenantId, ReturnIdentity $identity): ?ReturnContent
    {
        return $this->contents[self::key($tenantId, $identity)] ?? null;
    }

    public function save(TenantId $tenantId, ReturnIdentity $identity, ReturnContent $content): void
    {
        $this->contents[self::key($tenantId, $identity)] = $content;
    }

    private static function key(TenantId $tenantId, ReturnIdentity $identity): string
    {
        return serialize([$tenantId->value(), $identity->flowId()->value(), $identity->natureCode(), $identity->ops()]);
    }
}
