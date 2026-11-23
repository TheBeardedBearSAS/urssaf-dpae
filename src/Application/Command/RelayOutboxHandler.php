<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Command;

use TheBeardedBear\UrssafDpae\Application\Dto\RelayReport;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use Throwable;

/**
 * At-least-once delivery of the outbox: an event is marked as dispatched only once the sink accepted it. A failed event
 * stays pending, and the next events of the same record are not delivered in this run, so that the host receives the
 * events of a record in order. Runs must not overlap (the Symfony bridge takes a lock).
 */
final readonly class RelayOutboxHandler
{
    public function __construct(
        private Outbox $outbox,
        private IntegrationEventSink $sink,
        private Clock $clock,
    ) {}

    public function __invoke(RelayOutbox $command): RelayReport
    {
        $dispatched = 0;
        $failed = 0;
        $deferred = 0;
        /** @var array<string, array<string, bool>> $blocked tenant, then deposit or return, whose delivery failed in this run */
        $blocked = [];
        foreach ($this->outbox->pending($command->limit) as $entry) {
            $tenant = $entry->event->tenantId;
            $aggregate = $entry->event->aggregateId;
            if ($blocked[$tenant][$aggregate] ?? false) {
                ++$deferred;

                continue;
            }
            try {
                $this->sink->dispatch($entry->event);
            } catch (Throwable $failure) {
                $this->outbox->markFailed($entry, $failure->getMessage());
                $blocked[$tenant][$aggregate] = true;
                ++$failed;

                continue;
            }
            $this->outbox->markDispatched($entry, Instant::fromDateTime($this->clock->now()));
            ++$dispatched;
        }

        return new RelayReport($dispatched, $failed, $deferred);
    }
}
