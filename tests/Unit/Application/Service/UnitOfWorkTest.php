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

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived as DomainDepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FailingEventPublisher;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Journal;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\JournalingOutbox;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingEventPublisher;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingFailureReporter;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingTransactionManager;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UnitOfWorkTest extends TestCase
{
    private static function event(): DomainEvent
    {
        return new DomainDepositReceived(DepositId::fromString('d1'), Samples::tenant('t'), Samples::reference('r'), null, Samples::at(Samples::NOW));
    }

    public function testTheOutboxIsAppendedInsideTheTransactionAndEventsArePublishedAfterTheCommit(): void
    {
        $journal = new Journal();
        $publisher = new RecordingEventPublisher($journal);
        $outbox = new JournalingOutbox($journal);
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), $publisher, new IgnoredFailures(), $outbox);
        $event = self::event();

        $result = $unitOfWork->run(static function (RecordedEvents $events) use ($event): string {
            $events->record($event);

            return 'done';
        });

        static::assertSame('done', $result);
        static::assertSame(['begin', 'outbox', 'commit', 'publish'], $journal->entries());
        static::assertSame([$event], $publisher->events());
        static::assertCount(1, $outbox->events());
        static::assertInstanceOf(DepositReceived::class, $outbox->events()[0]);
    }

    public function testAFailureRollsBackAndPublishesNothing(): void
    {
        $journal = new Journal();
        $publisher = new RecordingEventPublisher($journal);
        $outbox = new JournalingOutbox($journal);
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), $publisher, new IgnoredFailures(), $outbox);

        $failure = Thrown::by(static fn(): string => $unitOfWork->run(static function (RecordedEvents $events): never {
            $events->record(self::event());

            throw new LogicException('boom');
        }));

        static::assertInstanceOf(LogicException::class, $failure);
        static::assertSame(['begin', 'rollback'], $journal->entries());
        static::assertSame([], $outbox->events());
        static::assertSame([], $publisher->events());
    }

    public function testAFailingListenerNeverUndoesTheWorkAndIsReported(): void
    {
        $journal = new Journal();
        $reporter = new RecordingFailureReporter();
        $unitOfWork = new UnitOfWork(new RecordingTransactionManager($journal), new FailingEventPublisher(), $reporter, new IgnoredOutbox());
        $event = self::event();

        $result = $unitOfWork->run(static function (RecordedEvents $events) use ($event): int {
            $events->record($event);

            return 42;
        });

        static::assertSame(42, $result);
        static::assertSame(['begin', 'commit'], $journal->entries());
        static::assertCount(1, $reporter->reports());
        static::assertSame('A listener failed.', $reporter->reports()[0][0]->getMessage());
        static::assertSame([$event], $reporter->reports()[0][1]);
    }

    public function testRecordedEventsKeepTheirOrder(): void
    {
        $recorded = new RecordedEvents();
        $first = self::event();
        $second = self::event();

        $recorded->record($first);
        $recorded->record($second, $first);

        static::assertSame([$first, $second, $first], $recorded->all());
    }

    public function testTheIgnoredOutboxKeepsNothing(): void
    {
        $outbox = new IgnoredOutbox();
        $outbox->append();
        $entry = new OutboxEntry(1, new DepositReceived('e', 'o', 't', 'a', 'r', null), 0);

        $outbox->markDispatched($entry, Samples::at(Samples::NOW));
        $outbox->markFailed($entry, 'x');

        static::assertSame([], $outbox->pending(10));
        static::assertSame(0, $outbox->purgeDispatchedBefore(Samples::at(Samples::NOW)));
    }
}
