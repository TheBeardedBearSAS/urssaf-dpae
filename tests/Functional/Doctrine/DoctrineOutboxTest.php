<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Event\NullEventPublisher;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract\OutboxContract;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DoctrineOutboxTest extends TestCase
{
    use OutboxContract;

    protected function createOutbox(): Outbox
    {
        return new DoctrineOutbox(TestDatabase::entityManager());
    }

    public function testTheEventsAreWrittenWithTheStateChangeOrNotAtAll(): void
    {
        $entityManager = TestDatabase::entityManager();
        $outbox = new DoctrineOutbox($entityManager);
        $unitOfWork = new UnitOfWork(new DoctrineTransactionManager($entityManager), new NullEventPublisher(), new IgnoredFailures(), $outbox);
        $unitOfWork->run(static fn(RecordedEvents $events) => $events->record(self::domainEvent('dep-1')));

        $failure = Outcome::of(static fn(): mixed => $unitOfWork->run(static function (RecordedEvents $events): never {
            $events->record(self::domainEvent('dep-2'));

            throw new LogicException('Rolled back.');
        }));

        static::assertInstanceOf(LogicException::class, $failure);
        $pending = $outbox->pending(10);
        static::assertCount(1, $pending);
        static::assertSame('urssaf.dpae.deposit.received', $pending[0]->event->type());
        $row = $entityManager->getConnection()->fetchAssociative('SELECT * FROM urssaf_dpae_outbox');
        static::assertIsArray($row);
        static::assertSame(
            [$pending[0]->event->eventId, 'urssaf.dpae.deposit.received', 1, 't1', 'dep-1', $pending[0]->event->occurredAt, null, 0, null],
            [$row['event_id'], $row['type'], $row['version'], $row['tenant_id'], $row['aggregate_id'], $row['occurred_at'], $row['dispatched_at'], $row['attempts'], $row['last_error']],
        );
        static::assertIsString($row['payload']);
        static::assertSame($pending[0]->event->toArray(), json_decode($row['payload'], true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTheLastFailureAndTheDispatchInstantAreKept(): void
    {
        $entityManager = TestDatabase::entityManager();
        $outbox = new DoctrineOutbox($entityManager);
        $outbox->append(new \TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived('e1', Samples::NOW, 't1', 'dep-1', 'ref-1', null));

        $outbox->markFailed($outbox->pending(1)[0], 'Broker down.');
        $outbox->markDispatched($outbox->pending(1)[0], Samples::at('2022-03-01T10:00:00Z'));

        static::assertSame('Broker down.', $entityManager->getConnection()->fetchOne('SELECT last_error FROM urssaf_dpae_outbox'));
        static::assertSame('2022-03-01 10:00:00', $entityManager->getConnection()->fetchOne('SELECT dispatched_at FROM urssaf_dpae_outbox'));
    }

    private static function domainEvent(string $depositId): DepositReceived
    {
        return new DepositReceived(
            DepositId::fromString($depositId),
            TenantId::fromString('t1'),
            Samples::reference('ref-' . $depositId),
            Samples::flowId('flow-1'),
            Samples::at(Samples::NOW),
        );
    }
}
