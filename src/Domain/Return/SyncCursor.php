<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Per-tenant position of the return synchronisation (ADR 0006): moved only by `SynchronizeReturns`, after a whole
 * listing was stored. Counts the consecutive polling refusals (422 / 429) to report a tenant needing attention.
 */
final readonly class SyncCursor
{
    private function __construct(
        private TenantId $tenantId,
        private ?Instant $position,
        private ?Instant $lastSuccessAt,
        private int $consecutiveRefusals,
    ) {}

    /**
     * A tenant never synchronised.
     */
    public static function initial(TenantId $tenantId): self
    {
        return new self($tenantId, null, null, 0);
    }

    /**
     * Restores a stored cursor.
     *
     * @throws InvalidValue `consecutive_refusals.negative`
     */
    public static function of(TenantId $tenantId, ?Instant $position, ?Instant $lastSuccessAt, int $consecutiveRefusals): self
    {
        if ($consecutiveRefusals < 0) {
            throw InvalidValue::negative('consecutive_refusals');
        }

        return new self($tenantId, $position, $lastSuccessAt, $consecutiveRefusals);
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    /**
     * The `from` of the next listing, null before the first successful one.
     */
    public function position(): ?Instant
    {
        return $this->position;
    }

    public function lastSuccessAt(): ?Instant
    {
        return $this->lastSuccessAt;
    }

    public function consecutiveRefusals(): int
    {
        return $this->consecutiveRefusals;
    }

    /**
     * After a successful synchronisation: the new position, and no refusal any more.
     */
    public function advancedTo(Instant $position, Instant $at): self
    {
        return new self($this->tenantId, $position, $at, 0);
    }

    /**
     * After a polling refusal: the position does not move.
     */
    public function refused(): self
    {
        return new self($this->tenantId, $this->position, $this->lastSuccessAt, $this->consecutiveRefusals + 1);
    }
}
