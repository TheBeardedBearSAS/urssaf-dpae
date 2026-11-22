<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Service\IntegrationEventTranslator;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown as DomainOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected as DomainRejected;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class IntegrationEventTranslatorTest extends TestCase
{
    use IntegrationEventTranslatorFixtures;

    public function testAReceivedDepositBecomesAPublicEvent(): void
    {
        $events = IntegrationEventTranslator::translate(self::received(Samples::flowId('F1')));

        static::assertCount(1, $events);
        $event = $events[0];
        static::assertInstanceOf(DepositReceived::class, $event);
        static::assertSame('urssaf.dpae.deposit.received', $event->type());
        static::assertSame(1, $event->version());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('F1', $event->flowId);
        static::assertMatchesRegularExpression(self::ID_FORMAT, $event->eventId);
        static::assertSame([
            'eventId' => $event->eventId,
            'occurredAt' => self::AT,
            'tenantId' => 'tenant-1',
            'aggregateId' => 'd1',
            'externalReference' => 'ref-1',
            'flowId' => 'F1',
        ], $event->toArray());
    }

    public function testTheEventIdIsStableAndDependsOnTheFacts(): void
    {
        $first = IntegrationEventTranslator::translate(self::received(Samples::flowId('F1')))[0];
        $again = IntegrationEventTranslator::translate(self::received(Samples::flowId('F1')))[0];
        $other = IntegrationEventTranslator::translate(self::received(Samples::flowId('F2')))[0];
        $unknownFlow = IntegrationEventTranslator::translate(self::received(null))[0];

        static::assertSame($first->eventId, $again->eventId);
        static::assertNotSame($first->eventId, $other->eventId);
        static::assertNotSame($first->eventId, $unknownFlow->eventId);
        static::assertInstanceOf(DepositReceived::class, $unknownFlow);
        static::assertNull($unknownFlow->flowId);
    }

    public function testARejectedDepositBecomesAPublicEvent(): void
    {
        $domain = new DomainRejected(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flowId('F1'), Samples::at(self::AT));
        $other = new DomainRejected(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flowId('F2'), Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(DepositRejected::class, $event);
        static::assertSame('urssaf.dpae.deposit.rejected', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('F1', $event->flowId);
        static::assertMatchesRegularExpression(self::ID_FORMAT, $event->eventId);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($other)[0]->eventId);
    }

    public function testAnUnknownOutcomeBecomesAPublicEventWithoutAnyMessage(): void
    {
        $domain = new DomainOutcomeUnknown(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.outcome_unknown', Samples::at(self::AT));
        $other = new DomainOutcomeUnknown(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.stale', Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(DepositOutcomeUnknown::class, $event);
        static::assertSame('urssaf.dpae.deposit.outcome_unknown', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('deposit.outcome_unknown', $event->errorCode);
        static::assertMatchesRegularExpression(self::ID_FORMAT, $event->eventId);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($other)[0]->eventId);
    }
}
