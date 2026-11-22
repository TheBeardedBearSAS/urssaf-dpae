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

use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Reconciles the deposits of one tenant whose answer was lost (ADR 0005). Never resends a flow.
 */
final readonly class ReconcileUnknownDeposits
{
    public function __construct(public TenantId $tenantId) {}
}
