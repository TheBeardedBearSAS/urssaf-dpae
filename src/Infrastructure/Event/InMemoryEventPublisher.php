<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Event;

use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Keeps the published events, in order: for tests and simple hosts.
 */
final class InMemoryEventPublisher implements EventPublisher
{
    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->events[] = $event;
        }
    }

    /**
     * @return list<DomainEvent>
     */
    public function published(): array
    {
        return $this->events;
    }
}
