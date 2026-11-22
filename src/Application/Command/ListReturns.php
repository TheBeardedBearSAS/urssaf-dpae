<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Command;

use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Lists the returns published from `$from` (one-date path) or in `[$from, $to]` (two-date path), and stores them
 * without moving the synchronisation cursor (ADR 0006).
 */
final readonly class ListReturns
{
    public function __construct(
        public TenantId $tenantId,
        public Instant $from,
        public ?Instant $to,
    ) {}
}
