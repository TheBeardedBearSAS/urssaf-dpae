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
 * The reconciliation matched a flux of URSSAF to the deposit (an inference, not an answer to the deposit): `state` is the
 * deposit's state afterwards (ADR 0005).
 */
final readonly class DepositAdopted extends IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.deposit.adopted';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        string $aggregateId,
        public string $externalReference,
        public string $flowId,
        public string $state,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId);
    }
}
