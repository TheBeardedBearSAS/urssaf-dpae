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

use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnAvailable as DomainReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnDownloaded as DomainReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Domain events of the returns to public integration events (ADR 0000 section 7), the counterpart of
 * {@see IntegrationEventTranslator} for the deposits.
 */
final class ReturnEventTranslator
{
    /**
     * @return ?IntegrationEvent null for an event that is not about a return
     */
    public static function of(DomainEvent $event): ?IntegrationEvent
    {
        return match (true) {
            $event instanceof DomainReturnAvailable => self::returnAvailable($event),
            $event instanceof DomainReturnDownloaded => self::returnDownloaded($event),
            default => null,
        };
    }

    private static function returnAvailable(DomainReturnAvailable $event): ReturnAvailable
    {
        $reference = $event->reference;
        $aggregateId = $reference->flowId()->value();

        return new ReturnAvailable(
            IntegrationEventFacts::id(
                ReturnAvailable::TYPE,
                $aggregateId,
                $event->occurredAt,
                [$reference->natureCode(), $reference->ops(), $reference->hasDocument()],
            ),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $reference->natureCode(),
            $reference->statusCode(),
            $reference->hasDocument(),
            $event->depositId?->value(),
        );
    }

    private static function returnDownloaded(DomainReturnDownloaded $event): ReturnDownloaded
    {
        $reference = $event->reference;
        $aggregateId = $reference->flowId()->value();

        return new ReturnDownloaded(
            IntegrationEventFacts::id(
                ReturnDownloaded::TYPE,
                $aggregateId,
                $event->occurredAt,
                [$reference->natureCode(), $reference->ops()],
            ),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $reference->natureCode(),
            $reference->statusCode(),
            $reference->hasDocument(),
            $event->depositId?->value(),
        );
    }
}
