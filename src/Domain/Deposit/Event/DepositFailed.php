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
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The deposit is closed without URSSAF having recorded anything (attempts exhausted, or the request was refused): the
 * host may deposit the same reference and flow again (ADR 0005).
 */
final readonly class DepositFailed implements DomainEvent
{
    public function __construct(
        public DepositId $depositId,
        public TenantId $tenantId,
        public ExternalReference $externalReference,
        public string $errorCode,
        public Instant $occurredAt,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }
}
