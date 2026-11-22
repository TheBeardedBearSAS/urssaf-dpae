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
 * One flux known to the simulated deposit system: the flow was recorded, and its receipt (or rejection notice) is the
 * nature `10` return.
 */
final readonly class SimulatedFlux
{
    public function __construct(
        public TenantId $tenantId,
        public FlowId $flowId,
        public string $returnId,
        public Instant $publishedAt,
        public bool $rejected,
        public string $document,
    ) {}
}
