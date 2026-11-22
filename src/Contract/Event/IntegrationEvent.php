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
 * Public, scalar event relayed at least once through the outbox (ADR 0000 section 7, ADR 0009: `urssaf.dpae.*`).
 * Carries identifiers and codes only: never a document, a reject reason, a name or a NIR.
 */
abstract readonly class IntegrationEvent
{
    /**
     * @var string
     */
    public const TYPE = 'urssaf.dpae.event';

    /**
     * @var positive-int
     */
    public const VERSION = 1;

    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $tenantId,
        public string $aggregateId,
    ) {}

    final public function type(): string
    {
        return static::TYPE;
    }

    final public function version(): int
    {
        return static::VERSION;
    }

    /**
     * The scalar payload, by property name.
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        // @mago-expect lint:inline-variable-return The variable carries the key type that get_object_vars() loses.
        /** @var array<string, mixed> $properties */
        $properties = get_object_vars($this);

        return $properties;
    }
}
