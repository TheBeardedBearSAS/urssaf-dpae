<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity;

use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Row of `urssaf_dpae_sync_cursors`, one per tenant (ADR 0006). Mapped for the schema tools, written and read with DBAL
 * by {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository}.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class SyncCursorRecord
{
    /** DBAL types of the columns of {@see self::row()}. */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'cursor_at' => InstantType::NAME,
        'last_success_at' => InstantType::NAME,
        'consecutive_refusals' => 'integer',
    ];

    private function __construct(
        protected TenantId $tenantId,
        private readonly ?Instant $cursorAt,
        private readonly ?Instant $lastSuccessAt,
        private readonly int $consecutiveRefusals,
    ) {}

    public static function of(SyncCursor $cursor): self
    {
        return new self($cursor->tenantId(), $cursor->position(), $cursor->lastSuccessAt(), $cursor->consecutiveRefusals());
    }

    /**
     * @return array{tenant_id: TenantId, cursor_at: Instant|null, last_success_at: Instant|null, consecutive_refusals: int}
     */
    public function row(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'cursor_at' => $this->cursorAt,
            'last_success_at' => $this->lastSuccessAt,
            'consecutive_refusals' => $this->consecutiveRefusals,
        ];
    }
}
