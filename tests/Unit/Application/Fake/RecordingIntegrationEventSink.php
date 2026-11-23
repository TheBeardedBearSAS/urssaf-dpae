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

use Override;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;

/**
 * Records the delivered events, and refuses the events of some aggregates.
 */
final class RecordingIntegrationEventSink implements IntegrationEventSink
{
    /** @var list<IntegrationEvent> */
    private array $events = [];

    /**
     * @param list<string> $refusedAggregates aggregate ids whose events make the sink fail
     */
    public function __construct(private readonly array $refusedAggregates) {}

    #[Override]
    public function dispatch(IntegrationEvent $event): void
    {
        if (\in_array($event->aggregateId, $this->refusedAggregates, true)) {
            throw new RuntimeException('The sink refused the event.');
        }
        $this->events[] = $event;
    }

    /**
     * @return list<IntegrationEvent>
     */
    public function events(): array
    {
        return $this->events;
    }
}
