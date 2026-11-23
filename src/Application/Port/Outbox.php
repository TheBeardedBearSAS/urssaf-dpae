<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port;

use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Integration events appended in the transaction of the state change and relayed at least once, in order.
 */
interface Outbox
{
    public function append(IntegrationEvent ...$events): void;

    /**
     * The oldest events not dispatched yet, in append order.
     *
     * @param positive-int $limit
     *
     * @return list<OutboxEntry>
     */
    public function pending(int $limit): array;

    public function markDispatched(OutboxEntry $entry, Instant $at): void;

    public function markFailed(OutboxEntry $entry, string $error): void;

    /**
     * Forgets the events dispatched before the instant; returns how many.
     */
    public function purgeDispatchedBefore(Instant $before): int;
}
