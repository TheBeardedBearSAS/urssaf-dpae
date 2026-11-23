<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Outbox;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class InMemoryOutboxTest extends TestCase
{
    private static function event(string $id): DepositReceived
    {
        return new DepositReceived($id, Samples::NOW, 't', 'd1', 'ref', null);
    }

    public function testEventsArePendingInAppendOrderWithTheirSequence(): void
    {
        $outbox = new InMemoryOutbox();
        $first = self::event('e1');
        $second = self::event('e2');
        $third = self::event('e3');

        static::assertSame([], $outbox->pending(10));

        $outbox->append($first, $second);
        $outbox->append($third);
        $pending = $outbox->pending(10);

        static::assertSame([1, 2, 3], array_map(static fn(OutboxEntry $entry): int => $entry->sequence, $pending));
        static::assertSame([$first, $second, $third], array_map(static fn(OutboxEntry $entry): IntegrationEvent => $entry->event, $pending));
        static::assertSame([0, 0, 0], array_map(static fn(OutboxEntry $entry): int => $entry->attempts, $pending));
    }

    public function testTheLimitKeepsTheOldestEvents(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1'), self::event('e2'), self::event('e3'));

        $pending = $outbox->pending(2);

        static::assertSame([1, 2], array_map(static fn(OutboxEntry $entry): int => $entry->sequence, $pending));
    }

    public function testADispatchedEventIsNoLongerPending(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1'), self::event('e2'));
        $first = $outbox->pending(10)[0];

        $outbox->markDispatched($first, Samples::at(Samples::NOW));

        $pending = $outbox->pending(10);
        static::assertCount(1, $pending);
        static::assertSame(2, $pending[0]->sequence);
    }

    public function testAFailedRelayCountsTheAttemptAndKeepsTheError(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1'));
        $entry = $outbox->pending(10)[0];

        static::assertNull($outbox->lastError(1));

        $outbox->markFailed($entry, 'first');
        $outbox->markFailed($outbox->pending(10)[0], 'second');

        static::assertSame(2, $outbox->pending(10)[0]->attempts);
        static::assertSame('second', $outbox->lastError(1));
    }

    public function testPurgingForgetsOnlyTheEventsDispatchedBeforeTheInstant(): void
    {
        $outbox = new InMemoryOutbox();
        $outbox->append(self::event('e1'), self::event('e2'), self::event('e3'), self::event('e4'));
        [$one, $two, $three] = $outbox->pending(3);
        $outbox->markDispatched($one, Samples::at('2022-02-01T10:00:00Z'));
        $outbox->markDispatched($two, Samples::at('2022-02-01T11:00:00Z'));
        $outbox->markDispatched($three, Samples::at('2022-02-01T12:00:00Z'));

        $purged = $outbox->purgeDispatchedBefore(Samples::at('2022-02-01T11:00:00Z'));

        static::assertSame(1, $purged);
        static::assertSame([4], array_map(static fn(OutboxEntry $entry): int => $entry->sequence, $outbox->pending(10)));
        static::assertSame(1, $outbox->purgeDispatchedBefore(Samples::at('2022-02-01T11:00:01Z')));
        static::assertSame(0, $outbox->purgeDispatchedBefore(Samples::at('2022-02-01T11:00:01Z')));
    }
}
