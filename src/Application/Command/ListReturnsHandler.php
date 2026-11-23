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

use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Lists returns on demand and stores them (ADR 0006). The synchronisation cursor is left where it is.
 */
final readonly class ListReturnsHandler
{
    public function __construct(
        private DpaeGateway $gateway,
        private ReturnIngestion $ingestion,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
    ) {}

    /**
     * @return list<ReturnReference>
     *
     * @throws PollingConstraintViolated HTTP 422 or 429, retryable
     * @throws InvariantViolation        `returns.range_inverted`
     */
    public function __invoke(ListReturns $command): array
    {
        $references = $this->gateway->listReturns($command->tenantId, $command->from, $command->to);
        $now = Instant::fromDateTime($this->clock->now());
        $this->unitOfWork->run(fn(RecordedEvents $events): int => $this->ingestion->ingest($command->tenantId, $references, $now, $events));

        return $references;
    }
}
