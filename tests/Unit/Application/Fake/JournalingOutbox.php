<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final class JournalingOutbox implements Outbox
{
    /**
     * @var list<IntegrationEvent>
     */
    private array $events = [];

    public function __construct(private readonly Journal $journal) {}

    public function append(IntegrationEvent ...$events): void
    {
        $this->journal->note('outbox');
        array_push($this->events, ...$events);
    }

    /**
     * @return list<IntegrationEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function pending(int $limit): array
    {
        return [];
    }

    public function markDispatched(OutboxEntry $entry, Instant $at): void {}

    public function markFailed(OutboxEntry $entry, string $error): void {}

    public function purgeDispatchedBefore(Instant $before): int
    {
        return 0;
    }
}
