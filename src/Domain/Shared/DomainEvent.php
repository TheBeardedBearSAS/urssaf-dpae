<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Shared;

/**
 * A fact recorded by an aggregate (ADR 0000 section 7). Domain events are published by the application layer after the
 * commit and translated into scalar integration events (`Contract\Event`) appended to the outbox in the transaction.
 */
interface DomainEvent
{
    public function occurredAt(): Instant;
}
