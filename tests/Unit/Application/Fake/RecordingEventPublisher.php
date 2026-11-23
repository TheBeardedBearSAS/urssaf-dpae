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

use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

final class RecordingEventPublisher implements EventPublisher
{
    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    public function __construct(private readonly Journal $journal) {}

    /**
     * @return list<DomainEvent>
     */
    public function events(): array
    {
        return $this->events;
    }

    public function publish(DomainEvent ...$events): void
    {
        $this->journal->note('publish');
        array_push($this->events, ...$events);
    }
}
