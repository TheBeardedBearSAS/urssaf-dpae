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
 * URSSAF refused the flow with a rejection notice; nothing was recorded. The aggregate id is the local deposit id.
 */
final readonly class DepositRejected extends IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.deposit.rejected';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        string $aggregateId,
        public string $externalReference,
        public ?string $flowId,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId);
    }
}
