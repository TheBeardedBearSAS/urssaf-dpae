<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Outbox;

use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Process-local outbox: events are kept in append order until dispatched. Not durable (ADR 0008).
 */
final class InMemoryOutbox implements Outbox
{
    /**
     * @var array<int, IntegrationEvent>
     */
    private array $events = [];

    /**
     * @var array<int, int>
     */
    private array $attempts = [];

    /**
     * @var array<int, string>
     */
    private array $errors = [];

    /**
     * @var array<int, Instant>
     */
    private array $dispatchedAt = [];

    private int $nextSequence = 1;

    public function append(IntegrationEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events[$this->nextSequence++] = $event;
        }
    }

    public function pending(int $limit): array
    {
        $pending = \array_slice(array_diff_key($this->events, $this->dispatchedAt), 0, $limit, true);

        return array_map(
            fn(int $sequence): OutboxEntry => new OutboxEntry($sequence, $pending[$sequence], $this->attempts[$sequence] ?? 0),
            array_keys($pending),
        );
    }

    public function markDispatched(OutboxEntry $entry, Instant $at): void
    {
        $this->dispatchedAt[$entry->sequence] = $at;
    }

    public function markFailed(OutboxEntry $entry, string $error): void
    {
        $this->attempts[$entry->sequence] = ($this->attempts[$entry->sequence] ?? 0) + 1;
        $this->errors[$entry->sequence] = $error;
    }

    public function purgeDispatchedBefore(Instant $before): int
    {
        $purged = array_filter($this->dispatchedAt, static fn(Instant $at): bool => $at->isBefore($before));
        $this->events = array_diff_key($this->events, $purged);
        $this->dispatchedAt = array_diff_key($this->dispatchedAt, $purged);

        return \count($purged);
    }

    /**
     * The error of the last failed relay of the entry, if any.
     */
    public function lastError(int $sequence): ?string
    {
        return $this->errors[$sequence] ?? null;
    }
}
