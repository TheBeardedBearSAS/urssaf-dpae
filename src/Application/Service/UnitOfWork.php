<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use Throwable;

/**
 * Runs one state change: in a transaction, with the integration events appended to the outbox inside it, then the
 * domain events published after the commit. A listener that fails never undoes the work (ADR 0000 section 7).
 */
final readonly class UnitOfWork
{
    public function __construct(
        private TransactionManager $transactions,
        private EventPublisher $publisher,
        private FailureReporter $failureReporter,
        private Outbox $outbox,
    ) {}

    /**
     * @template T
     *
     * @param-immediately-invoked-callable $operation
     *
     * @param callable(RecordedEvents): T $operation records its domain events in the collector it receives
     *
     * @return T
     */
    public function run(callable $operation): mixed
    {
        $events = new RecordedEvents();
        $result = $this->transactions->transactional(function () use ($operation, $events): mixed {
            $result = $operation($events);
            $this->outbox->append(...IntegrationEventTranslator::translate(...$events->all()));

            return $result;
        });

        try {
            $this->publisher->publish(...$events->all());
        } catch (Throwable $failure) {
            $this->failureReporter->eventPublicationFailed($failure, $events->all());
        }

        return $result;
    }
}
