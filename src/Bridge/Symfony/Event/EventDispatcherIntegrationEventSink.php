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
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;

/**
 * Delivers the integration events to the listeners of the Symfony event dispatcher (in the relay process): a listener
 * that throws keeps the event in the outbox.
 */
final readonly class EventDispatcherIntegrationEventSink implements IntegrationEventSink
{
    public function __construct(private EventDispatcherInterface $dispatcher) {}

    #[Override]
    public function dispatch(IntegrationEvent $event): void
    {
        $this->dispatcher->dispatch($event);
    }
}
