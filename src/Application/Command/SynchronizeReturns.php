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
 * Synchronises the returns of one tenant from its cursor (ADR 0006). The host runs it under a non-blocking lock per
 * tenant (ADR 0009).
 */
final readonly class SynchronizeReturns
{
    public function __construct(public TenantId $tenantId) {}
}
