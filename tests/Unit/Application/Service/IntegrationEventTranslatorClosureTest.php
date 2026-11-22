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
use TheBeardedBear\UrssafDpae\Contract\Event\DepositAdopted;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositFailed;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositParked;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositAdopted as DomainAdopted;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositFailed as DomainFailed;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositParked as DomainParked;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * The public events of a deposit that is closed without a receipt, parked, or matched by the reconciliation.
 */
final class IntegrationEventTranslatorClosureTest extends TestCase
{
    private const AT = '2022-02-01T12:00:00Z';

    public function testAFailedDepositBecomesAPublicEvent(): void
    {
        $domain = new DomainFailed(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.not_sent', Samples::at(self::AT));
        $other = new DomainFailed(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.not_processed', Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(DepositFailed::class, $event);
        static::assertSame('urssaf.dpae.deposit.failed', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('deposit.not_sent', $event->errorCode);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($other)[0]->eventId);
    }

    public function testAParkedDepositBecomesAPublicEvent(): void
    {
        $domain = new DomainParked(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.invalid_credentials', Samples::at(self::AT));
        $other = new DomainParked(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), 'deposit.other', Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(DepositParked::class, $event);
        static::assertSame('urssaf.dpae.deposit.parked', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('deposit.invalid_credentials', $event->errorCode);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($other)[0]->eventId);
    }

    public function testAnAdoptedDepositBecomesAPublicEventNamingTheFluxAndTheState(): void
    {
        $domain = new DomainAdopted(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flowId('F1'), DepositState::Received, Samples::at(self::AT));
        $otherFlux = new DomainAdopted(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flowId('F2'), DepositState::Received, Samples::at(self::AT));
        $otherState = new DomainAdopted(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), Samples::flowId('F1'), DepositState::Rejected, Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(DepositAdopted::class, $event);
        static::assertSame('urssaf.dpae.deposit.adopted', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('d1', $event->aggregateId);
        static::assertSame('ref-1', $event->externalReference);
        static::assertSame('F1', $event->flowId);
        static::assertSame('received', $event->state);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($otherFlux)[0]->eventId);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($otherState)[0]->eventId);
    }
}
