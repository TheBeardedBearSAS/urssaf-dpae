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
 * The deposit is closed and URSSAF recorded nothing: the host may deposit the same reference and flow again (ADR 0005).
 */
final readonly class DepositFailed extends IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.deposit.failed';

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
