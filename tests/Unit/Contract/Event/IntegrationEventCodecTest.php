<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Contract\Event;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositAdopted;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositFailed;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositParked;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEventCodec;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnDownloaded;

final class IntegrationEventCodecTest extends TestCase
{
    /**
     * @return list<IntegrationEvent>
     */
    private static function events(): array
    {
        return [
            new DepositReceived('e1', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', 'F1'),
            new DepositRejected('e2', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', null),
            new DepositOutcomeUnknown('e3', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', 'deposit.outcome_unknown'),
            new DepositFailed('e6', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', 'deposit.not_sent'),
            new DepositParked('e7', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', 'deposit.invalid_credentials'),
            new DepositAdopted('e8', '2022-02-01T12:00:00Z', 't', 'd1', 'ref', 'F1', 'received'),
            new ReturnAvailable('e4', '2022-02-01T12:00:00Z', 't', 'F1', 10, 'OK', true, 'd1'),
            new ReturnDownloaded('e5', '2022-02-01T12:00:00Z', 't', 'F1', 10, 'OK', true, null),
        ];
    }

    public function testEveryEventSurvivesAnEncodeDecodeRoundTrip(): void
    {
        foreach (self::events() as $event) {
            $decoded = IntegrationEventCodec::decode(IntegrationEventCodec::encode($event));

            static::assertEquals($event, $decoded);
            static::assertSame($event::class, $decoded::class);
        }
    }

    public function testTheCodecKnowsEveryEventClass(): void
    {
        static::assertSame(IntegrationEventCodec::CLASSES, array_map(static fn(IntegrationEvent $event): string => $event::class, self::events()));
    }

    public function testEveryEventHasItsOwnTypeAndAVersion(): void
    {
        $types = array_map(static fn(IntegrationEvent $event): string => $event->type(), self::events());

        static::assertSame([
            'urssaf.dpae.deposit.received',
            'urssaf.dpae.deposit.rejected',
            'urssaf.dpae.deposit.outcome_unknown',
            'urssaf.dpae.deposit.failed',
            'urssaf.dpae.deposit.parked',
            'urssaf.dpae.deposit.adopted',
            'urssaf.dpae.return.available',
            'urssaf.dpae.return.downloaded',
        ], $types);
        foreach (self::events() as $event) {
            static::assertSame(1, $event->version());
        }
    }

    public function testDataThatIsNotAnEventIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntegrationEventCodec::decode(serialize(['not' => 'an event']));
    }

    public function testAClassOutsideTheListIsNeverRebuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IntegrationEventCodec::decode(IntegrationEventCodec::encode(new ForeignEvent('e', '2022-02-01T12:00:00Z', 't', 'a')));
    }
}
