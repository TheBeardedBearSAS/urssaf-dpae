<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity;

use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Row of the outbox table. Mapped so that the schema tools and migrations know the table; written and read with DBAL by
 * {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineOutbox}, never through the ORM.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class OutboxMessage
{
    /** DBAL types of the columns of {@see self::row()} and of the updates (the others are plain strings). */
    public const TYPES = [
        'version' => 'integer',
        'payload' => 'json',
        'data' => 'text',
        'dispatched_at' => InstantType::NAME,
        'attempts' => 'integer',
        'last_error' => 'text',
    ];

    /**
     * @param ?int                 $sequence assigned by the database (identity column): null until the row is read back
     * @param array<string, mixed> $payload
     */
    private function __construct(
        // @mago-expect analysis:unused-property Mapped identifier, assigned by the database and read with DBAL only, never through the ORM.
        protected ?int $sequence,
        private readonly string $eventId,
        private readonly string $type,
        private readonly int $version,
        private readonly string $tenantId,
        private readonly string $aggregateId,
        private readonly string $occurredAt,
        private readonly array $payload,
        private readonly string $data,
        private readonly ?Instant $dispatchedAt,
        private readonly int $attempts,
        private readonly ?string $lastError,
    ) {}

    public static function of(IntegrationEvent $event): self
    {
        return new self(
            null,
            $event->eventId,
            $event->type(),
            $event->version(),
            $event->tenantId,
            $event->aggregateId,
            $event->occurredAt,
            $event->toArray(),
            IntegrationEventCodec::encode($event),
            null,
            0,
            null,
        );
    }

    /**
     * The columns of the new, pending entry (the sequence is assigned by the database).
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'version' => $this->version,
            'tenant_id' => $this->tenantId,
            'aggregate_id' => $this->aggregateId,
            'occurred_at' => $this->occurredAt,
            'payload' => $this->payload,
            'data' => $this->data,
            'dispatched_at' => $this->dispatchedAt,
            'attempts' => $this->attempts,
            'last_error' => $this->lastError,
        ];
    }
}
