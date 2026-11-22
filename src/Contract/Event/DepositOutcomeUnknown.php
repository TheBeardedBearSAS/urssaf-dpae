<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Contract\Event;

/**
 * The answer of a deposit was lost: the flow is reconciled, never resent (ADR 0005).
 */
final readonly class DepositOutcomeUnknown extends IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.deposit.outcome_unknown';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        string $aggregateId,
        public string $externalReference,
        public string $errorCode,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId);
    }
}
