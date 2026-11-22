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
 * A return was first listed, or gained a document. The aggregate id is the `flux.id`.
 */
final readonly class ReturnAvailable extends IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.return.available';

    public function __construct(
        string $eventId,
        string $occurredAt,
        string $tenantId,
        string $aggregateId,
        public int $nature,
        public string $status,
        public bool $hasDocument,
        public ?string $depositId,
    ) {
        parent::__construct($eventId, $occurredAt, $tenantId, $aggregateId);
    }
}
