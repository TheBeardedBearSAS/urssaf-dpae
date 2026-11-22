<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Command;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\PurgeOutbox;
use TheBeardedBear\UrssafDpae\Application\Command\PurgeOutboxHandler;
use TheBeardedBear\UrssafDpae\Application\Command\RelayOutbox;
use TheBeardedBear\UrssafDpae\Application\Command\RelayOutboxHandler;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class RelayOutboxHandlerTest extends TestCase
{
    private static function event(string $id, string $aggregate, string $tenant): DepositReceived
    {
        return new DepositReceived($id, Samples::NOW, $tenant, $aggregate, 'ref', null);
    }

    public function testItDeliversTheEventsInOrderAndMarksThemDispatched(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1', 'd1', 't'), self::event('e2', 'd2', 't'));
        $sink = new RecordingIntegrationEventSink([]);

        $report = (new RelayOutboxHandler($outbox, $sink, new FixedClock(Samples::NOW)))(new RelayOutbox(10));

        static::assertSame([2, 0, 0], [$report->dispatched, $report->failed, $report->deferred]);
        static::assertSame(['e1', 'e2'], array_map(static fn(IntegrationEvent $event): string => $event->eventId, $sink->events()));
        static::assertSame([], $outbox->pending(10));
    }

    public function testAFailedEventStaysPendingAndBlocksTheLaterEventsOfTheSameDepositOnly(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(
            self::event('e1', 'refused-aggregate', 't'),
            self::event('e2', 'refused-aggregate', 't'),
            self::event('e3', 'other', 't'),
            self::event('e4', 'refused-aggregate', 'another-tenant'),
        );
        $sink = new RecordingIntegrationEventSink(['refused-aggregate']);

        $report = (new RelayOutboxHandler($outbox, $sink, new FixedClock(Samples::NOW)))(new RelayOutbox(10));

        static::assertSame([1, 2, 1], [$report->dispatched, $report->failed, $report->deferred]);
        $pending = $outbox->pending(10);
        static::assertSame(['e1', 'e2', 'e4'], array_map(static fn(OutboxEntry $entry): string => $entry->event->eventId, $pending));
        static::assertSame([1, 0, 1], array_map(static fn(OutboxEntry $entry): int => $entry->attempts, $pending));
    }

    public function testTheLimitBoundsTheRun(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1', 'd1', 't'), self::event('e2', 'd2', 't'));

        $report = (new RelayOutboxHandler($outbox, new RecordingIntegrationEventSink([]), new FixedClock(Samples::NOW)))(new RelayOutbox(1));

        static::assertSame(1, $report->dispatched);
        static::assertCount(1, $outbox->pending(10));
    }

    public function testThePurgeForgetsTheEventsDispatchedBeforeTheRetention(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1', 'd1', 't'));
        $clock = new FixedClock('2022-02-01T12:00:00Z');
        (new RelayOutboxHandler($outbox, new RecordingIntegrationEventSink([]), $clock))(new RelayOutbox(10));
        $clock->moveTo('2022-03-01T12:00:00Z');

        static::assertSame(0, (new PurgeOutboxHandler($outbox, $clock))(new PurgeOutbox(new DateInterval('P30D'))));
        static::assertSame(1, (new PurgeOutboxHandler($outbox, $clock))(new PurgeOutbox(new DateInterval('P27D'))));
    }
}
