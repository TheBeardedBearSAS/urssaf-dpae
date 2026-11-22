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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The reconciliation matched a flux published by URSSAF to the deposit and gave it its flow id: an inference, not an
 * answer URSSAF gave to the deposit (ADR 0005). Recorded in addition to `DepositReceived` / `DepositRejected` when the
 * outcome was unknown.
 */
final readonly class DepositAdopted implements DomainEvent
{
    public function __construct(
        public DepositId $depositId,
        public TenantId $tenantId,
        public ExternalReference $externalReference,
        public FlowId $flowId,
        public DepositState $state,
        public Instant $occurredAt,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }
}
