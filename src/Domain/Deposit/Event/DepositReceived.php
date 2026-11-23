<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit\Event;

use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * URSSAF recorded the declaration: an electronic receipt was obtained (or an unknown outcome was resolved as received).
 */
final readonly class DepositReceived implements DomainEvent
{
    public function __construct(
        public DepositId $depositId,
        public TenantId $tenantId,
        public ExternalReference $externalReference,
        public ?FlowId $flowId,
        public Instant $occurredAt,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }
}
