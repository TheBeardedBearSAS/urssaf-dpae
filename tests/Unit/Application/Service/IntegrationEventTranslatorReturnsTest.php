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
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnAvailable as DomainReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnDownloaded as DomainReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class IntegrationEventTranslatorReturnsTest extends TestCase
{
    use IntegrationEventTranslatorFixtures;

    public function testANewReturnBecomesAPublicEvent(): void
    {
        $reference = SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS01');

        $event = IntegrationEventTranslator::translate(new DomainReturnAvailable(Samples::tenant('tenant-1'), $reference, DepositId::fromString('d1'), Samples::at(self::AT)))[0];

        static::assertInstanceOf(ReturnAvailable::class, $event);
        static::assertSame('urssaf.dpae.return.available', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('F1', $event->aggregateId);
        static::assertSame(10, $event->nature);
        static::assertSame('OK', $event->status);
        static::assertTrue($event->hasDocument);
        static::assertSame('d1', $event->depositId);
        static::assertMatchesRegularExpression(self::ID_FORMAT, $event->eventId);
    }

    public function testAReturnWithoutDepositHasNoDepositId(): void
    {
        $reference = SampleReturns::listedWithoutDocument('F1', 10, 'EN_COURS', self::AT, null);

        $event = IntegrationEventTranslator::translate(new DomainReturnAvailable(Samples::tenant('tenant-1'), $reference, null, Samples::at(self::AT)))[0];

        static::assertInstanceOf(ReturnAvailable::class, $event);
        static::assertNull($event->depositId);
        static::assertFalse($event->hasDocument);
    }

    public function testTheReturnEventIdDependsOnItsNatureOpsDocumentAndInstant(): void
    {
        $id = static fn(ReturnReference $reference, string $at): string => IntegrationEventTranslator::translate(
            new DomainReturnAvailable(Samples::tenant('tenant-1'), $reference, null, Samples::at($at)),
        )[0]->eventId;
        $base = $id(SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS01'), self::AT);

        static::assertSame($base, $id(SampleReturns::listed('F1', 10, 'KO', self::AT, 'OPS01'), self::AT));
        static::assertNotSame($base, $id(SampleReturns::listed('F1', 11, 'OK', self::AT, 'OPS01'), self::AT));
        static::assertNotSame($base, $id(SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS02'), self::AT));
        static::assertNotSame($base, $id(SampleReturns::listedWithoutDocument('F1', 10, 'OK', self::AT, 'OPS01'), self::AT));
        static::assertNotSame($base, $id(SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS01'), '2022-02-01T12:00:01Z'));
        static::assertNotSame($base, $id(SampleReturns::listed('F2', 10, 'OK', self::AT, 'OPS01'), self::AT));
    }

    public function testADownloadedReturnBecomesAPublicEvent(): void
    {
        $reference = SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS01');
        $domain = new DomainReturnDownloaded(Samples::tenant('tenant-1'), $reference, DepositId::fromString('d1'), Samples::at(self::AT));

        $event = IntegrationEventTranslator::translate($domain)[0];

        static::assertInstanceOf(ReturnDownloaded::class, $event);
        static::assertSame('urssaf.dpae.return.downloaded', $event->type());
        static::assertSame(self::AT, $event->occurredAt);
        static::assertSame('tenant-1', $event->tenantId);
        static::assertSame('F1', $event->aggregateId);
        static::assertSame(10, $event->nature);
        static::assertSame('OK', $event->status);
        static::assertTrue($event->hasDocument);
        static::assertSame('d1', $event->depositId);
        static::assertMatchesRegularExpression(self::ID_FORMAT, $event->eventId);
        $otherNature = new DomainReturnDownloaded(Samples::tenant('tenant-1'), SampleReturns::listed('F1', 11, 'OK', self::AT, 'OPS01'), null, Samples::at(self::AT));
        $otherOps = new DomainReturnDownloaded(Samples::tenant('tenant-1'), SampleReturns::listed('F1', 10, 'OK', self::AT, 'OPS02'), null, Samples::at(self::AT));
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($otherNature)[0]->eventId);
        static::assertNotSame($event->eventId, IntegrationEventTranslator::translate($otherOps)[0]->eventId);
        $withoutDeposit = IntegrationEventTranslator::translate($otherOps)[0];
        static::assertInstanceOf(ReturnDownloaded::class, $withoutDeposit);
        static::assertNull($withoutDeposit->depositId);
    }
}
