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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturn;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturns;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Application\Port\OutboxEntry;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Standalone\Persistence;
use TheBeardedBear\UrssafDpae\Standalone\UrssafDpae;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SequentialIdGenerator;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Standalone\UrssafDpaeBuilderFixtures;

/**
 * The whole lifecycle of the standalone entry point on the Doctrine persistence, the way a host wires it.
 */
final class DoctrineLifecycleTest extends TestCase
{
    use UrssafDpaeBuilderFixtures;

    public function testTheWholeLifecycleRunsOnTheDoctrinePersistence(): void
    {
        $entityManager = TestDatabase::entityManager();
        $clock = new FixedClock(Samples::NOW);
        $dpae = self::dpae($entityManager, self::simulated(), $clock);
        $tenant = Samples::tenant(HttpStack::TENANT);

        $deposit = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $again = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $report = $dpae->synchronizeReturns()(new SynchronizeReturns($tenant));
        $listed = $dpae->listReturns()(new ListReturns($tenant, Samples::at('2022-01-01T00:00:00Z'), null));
        $content = $dpae->downloadReturn()(new DownloadReturn($tenant, $listed[0]->identity()));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame($deposit->id()->value(), $again->id()->value());
        static::assertSame(1, $report->discovered);
        static::assertStringContainsString('IdFluxDPAE', $content->bytes());
        $persistence = self::persistence($entityManager, $clock);
        static::assertSame(DepositState::Received, $persistence->deposits->get($deposit->id())->state());
        static::assertSame(DownloadState::Downloaded, $persistence->returns->find($tenant, $listed[0]->identity())?->downloadState());
        static::assertSame($content->bytes(), $persistence->contents->find($tenant, $listed[0]->identity())?->bytes());
        static::assertNotNull($persistence->cursors->find($tenant));
        static::assertNotSame([], $persistence->outbox->pending(10));
        static::assertSame(
            ['urssaf.dpae.deposit.received', 'urssaf.dpae.return.available', 'urssaf.dpae.return.downloaded'],
            array_values(array_unique(array_map(static fn(OutboxEntry $entry): string => $entry->event->type(), $persistence->outbox->pending(10)))),
        );
    }

    public function testTheLostAnswerOfADepositIsReconciledAfterwards(): void
    {
        $entityManager = TestDatabase::entityManager();
        $simulator = self::simulated();
        $simulator->loseNextAnswer();
        $clock = new FixedClock(Samples::NOW);
        $dpae = self::dpae($entityManager, $simulator, $clock);
        $tenant = Samples::tenant(HttpStack::TENANT);

        $deposit = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $again = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $clock->moveTo('2022-02-01T13:30:00Z');
        $report = $dpae->reconcileUnknownDeposits()(new ReconcileUnknownDeposits($tenant));

        static::assertSame(DepositState::OutcomeUnknown, $deposit->state());
        static::assertSame($deposit->id()->value(), $again->id()->value());
        static::assertSame([$deposit->id()->value()], array_map(static fn(DepositId $id): string => $id->value(), $report->adopted));
        $adopted = self::persistence($entityManager, $clock)->deposits->get($deposit->id());
        static::assertSame(DepositState::Received, $adopted->state());
        static::assertTrue($adopted->adoptedByReconciliation());
        static::assertSame('id-1', $adopted->flowId()?->value());
        static::assertCount(1, $simulator->deposited());
    }

    private static function dpae(EntityManagerInterface $entityManager, SimulatedDpaeGateway $gateway, FixedClock $clock): UrssafDpae
    {
        return self::builder(self::stack(ApiConfiguration::defaults()))
            ->withGateway($gateway)
            ->withClock($clock)
            ->withIdGenerator(new SequentialIdGenerator())
            ->withPersistence(self::persistence($entityManager, $clock))
            ->build();
    }

    private static function persistence(EntityManagerInterface $entityManager, FixedClock $clock): Persistence
    {
        return new Persistence(
            new DoctrineDepositRepository($entityManager),
            new DoctrineReturnRepository($entityManager),
            new DoctrineSyncCursorRepository($entityManager),
            new DoctrineReturnContentStore($entityManager, $clock),
            new DoctrineOutbox($entityManager),
            new DoctrineTransactionManager($entityManager),
        );
    }
}
