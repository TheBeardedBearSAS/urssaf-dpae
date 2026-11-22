<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Event;

use Override;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Publishes the domain events on the Symfony event dispatcher, one by one, in order: a listener that throws interrupts
 * the publication, which the unit of work reports without undoing the committed work.
 */
final readonly class SymfonyEventPublisher implements EventPublisher
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }
    }
}
