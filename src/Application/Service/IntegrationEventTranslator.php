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

use TheBeardedBear\UrssafDpae\Contract\Event\DepositAdopted;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositFailed;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositParked;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositAdopted as DomainDepositAdopted;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositFailed as DomainDepositFailed;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown as DomainDepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositParked as DomainDepositParked;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived as DomainDepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected as DomainDepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Domain events to public integration events (ADR 0000 section 7). A domain event with no public counterpart gives none.
 */
final class IntegrationEventTranslator
{
    /**
     * @return list<IntegrationEvent>
     */
    public static function translate(DomainEvent ...$events): array
    {
        $translated = [];
        foreach ($events as $event) {
            $integration = self::one($event);
            if ($integration instanceof IntegrationEvent) {
                $translated[] = $integration;
            }
        }

        return $translated;
    }

    private static function one(DomainEvent $event): ?IntegrationEvent
    {
        $answer = self::ofAnswer($event);
        if ($answer instanceof IntegrationEvent) {
            return $answer;
        }
        $closure = self::ofClosure($event);

        return $closure instanceof IntegrationEvent ? $closure : ReturnEventTranslator::of($event);
    }

    private static function ofAnswer(DomainEvent $event): ?IntegrationEvent
    {
        return match (true) {
            $event instanceof DomainDepositReceived => self::received($event),
            $event instanceof DomainDepositRejected => self::rejected($event),
            $event instanceof DomainDepositOutcomeUnknown => self::outcomeUnknown($event),
            default => null,
        };
    }

    private static function ofClosure(DomainEvent $event): ?IntegrationEvent
    {
        return match (true) {
            $event instanceof DomainDepositFailed => self::failed($event),
            $event instanceof DomainDepositParked => self::parked($event),
            $event instanceof DomainDepositAdopted => self::adopted($event),
            default => null,
        };
    }

    private static function received(DomainDepositReceived $event): DepositReceived
    {
        $flowId = $event->flowId?->value();
        $aggregateId = $event->depositId->value();

        return new DepositReceived(
            IntegrationEventFacts::id(DepositReceived::TYPE, $aggregateId, $event->occurredAt, [$flowId]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $flowId,
        );
    }

    private static function rejected(DomainDepositRejected $event): DepositRejected
    {
        $flowId = $event->flowId?->value();
        $aggregateId = $event->depositId->value();

        return new DepositRejected(
            IntegrationEventFacts::id(DepositRejected::TYPE, $aggregateId, $event->occurredAt, [$flowId]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $flowId,
        );
    }

    private static function outcomeUnknown(DomainDepositOutcomeUnknown $event): DepositOutcomeUnknown
    {
        $aggregateId = $event->depositId->value();

        return new DepositOutcomeUnknown(
            IntegrationEventFacts::id(DepositOutcomeUnknown::TYPE, $aggregateId, $event->occurredAt, [$event->errorCode]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $event->errorCode,
        );
    }

    private static function failed(DomainDepositFailed $event): DepositFailed
    {
        $aggregateId = $event->depositId->value();

        return new DepositFailed(
            IntegrationEventFacts::id(DepositFailed::TYPE, $aggregateId, $event->occurredAt, [$event->errorCode]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $event->errorCode,
        );
    }

    private static function parked(DomainDepositParked $event): DepositParked
    {
        $aggregateId = $event->depositId->value();

        return new DepositParked(
            IntegrationEventFacts::id(DepositParked::TYPE, $aggregateId, $event->occurredAt, [$event->errorCode]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $event->errorCode,
        );
    }

    private static function adopted(DomainDepositAdopted $event): DepositAdopted
    {
        $aggregateId = $event->depositId->value();
        $flowId = $event->flowId->value();

        return new DepositAdopted(
            IntegrationEventFacts::id(DepositAdopted::TYPE, $aggregateId, $event->occurredAt, [$flowId, $event->state->value]),
            $event->occurredAt->toIso8601(),
            $event->tenantId->value(),
            $aggregateId,
            $event->externalReference->value(),
            $flowId,
            $event->state->value,
        );
    }
}
