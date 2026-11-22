<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Testing;

use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The flux of the simulated deposit system, in deposit order, and which of them were purged.
 */
final class SimulatedFluxes
{
    /**
     * @var list<SimulatedFlux>
     */
    private array $fluxes = [];

    /**
     * @var list<string>
     */
    private array $purged = [];

    public function add(SimulatedFlux $flux): void
    {
        $this->fluxes[] = $flux;
    }

    public function count(): int
    {
        return \count($this->fluxes);
    }

    public function purge(FlowId $flowId): void
    {
        $this->purged[] = $flowId->value();
    }

    public function isPurged(FlowId $flowId): bool
    {
        return \in_array($flowId->value(), $this->purged, true);
    }

    public function find(FlowId $flowId): ?SimulatedFlux
    {
        foreach ($this->fluxes as $flux) {
            if ($flux->flowId->equals($flowId)) {
                return $flux;
            }
        }

        return null;
    }

    /**
     * The tenant's flux published in `[$from, $to]` (no upper bound when `$to` is null), bounds included.
     *
     * @return list<SimulatedFlux>
     */
    public function published(TenantId $tenantId, Instant $from, ?Instant $to): array
    {
        return array_values(array_filter(
            $this->fluxes,
            static fn(SimulatedFlux $flux): bool => $flux->tenantId->equals($tenantId)
                && !$flux->publishedAt->isBefore($from)
                && (!$to instanceof Instant || !$flux->publishedAt->isAfter($to)),
        ));
    }
}
