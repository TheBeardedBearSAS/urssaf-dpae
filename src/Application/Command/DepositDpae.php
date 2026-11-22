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

use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Deposits one flow for a tenant. The flow carries its own {@see \TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode}
 * (ADR 0003): there is no separate mode argument, so none can be defaulted.
 */
final readonly class DepositDpae
{
    public function __construct(
        public TenantId $tenantId,
        public ExternalReference $externalReference,
        public DpaeFlow $flow,
    ) {}
}
