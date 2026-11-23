<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return\Event;

use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The document of a return was downloaded.
 */
final readonly class ReturnDownloaded implements DomainEvent
{
    public function __construct(
        public TenantId $tenantId,
        public ReturnReference $reference,
        public ?DepositId $depositId,
        public Instant $occurredAt,
    ) {}

    public function occurredAt(): Instant
    {
        return $this->occurredAt;
    }
}
