<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\DepositRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositFlowStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineOutbox;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\LazyGhostOption;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Rows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class DoctrineWiringTest extends TestCase
{
    public function testTheDoctrineDriverWiresThePersistence(): void
    {
        $harness = self::doctrine([]);

        static::assertInstanceOf(DoctrineDepositRepository::class, $harness->privateService(DepositRepository::class, DepositRepository::class));
        static::assertInstanceOf(DoctrineReturnRepository::class, $harness->privateService(ReturnRepository::class, ReturnRepository::class));
        static::assertInstanceOf(DoctrineSyncCursorRepository::class, $harness->privateService(SyncCursorRepository::class, SyncCursorRepository::class));
        static::assertInstanceOf(DoctrineReturnContentStore::class, $harness->privateService(ReturnContentStore::class, ReturnContentStore::class));
        static::assertInstanceOf(DoctrineTransactionManager::class, $harness->privateService(TransactionManager::class, TransactionManager::class));
        static::assertFalse($harness->has(DoctrineDepositFlowStore::class));
    }

    public function testTheMappingAndTheTypesAreRegistered(): void
    {
        $harness = self::doctrine([]);
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);

        static::assertFalse($entityManager->getMetadataFactory()->isTransient(DepositRecord::class));
        foreach (array_keys(DoctrineIntegration::types()) as $type) {
            static::assertTrue(Type::hasType($type), $type);
        }
    }

    public function testTheWorkflowRunsOnTheDatabase(): void
    {
        $harness = self::doctrine(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]);
        $harness->createDatabaseSchema();

        $deposit = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $again = Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $sync = $harness->command('urssaf:dpae:sync-returns');
        $harness->clock->modify('+1 minute');

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame($deposit->id()->value(), $again->id()->value());
        static::assertSame(0, $sync->execute([]));
        $stored = $harness->privateService(DepositRepository::class, DepositRepository::class)->findOpenByReference(TenantId::fromString('tenant-1'), ExternalReference::fromString('ref-1'));
        static::assertSame(DepositState::Received, $stored?->state());
        $connection = $harness->privateService('doctrine.dbal.default_connection', Connection::class);
        static::assertSame(1, Rows::count($connection, 'urssaf_dpae_deposits'));
        static::assertSame(1, Rows::count($connection, 'urssaf_dpae_sync_cursors'));
        $outbox = $harness->privateService(Outbox::class, Outbox::class);
        static::assertInstanceOf(DoctrineOutbox::class, $outbox);
        static::assertGreaterThan(0, \count($outbox->pending(100)));
        static::assertSame(\count($outbox->pending(100)), Rows::count($connection, 'urssaf_dpae_outbox'));
    }

    public function testTheKeptFlowStoreIsAPublicServiceOnDemand(): void
    {
        $harness = self::doctrine(['persistence' => ['driver' => 'doctrine', 'keep_flows' => true]]);

        static::assertInstanceOf(DoctrineDepositFlowStore::class, $harness->get(DoctrineDepositFlowStore::class));
    }

    public function testTheSchemaCheckFailsUntilTheTablesExist(): void
    {
        $harness = self::doctrine([]);

        $missing = $harness->command('urssaf:dpae:check');
        static::assertSame(1, $missing->execute(['--skip-remote' => true]));
        static::assertStringContainsString('schema change(s) pending on the package tables', $missing->getDisplay());

        $harness->createDatabaseSchema();
        $created = $harness->command('urssaf:dpae:check');
        static::assertSame(0, $created->execute(['--skip-remote' => true]));
        $rows = Output::rows($created->getDisplay());
        static::assertContains(['database schema', 'default', 'ok', 'The package tables match the mapping.'], $rows);
        static::assertContains(['entity manager', 'default', 'warning', 'Shared with the application: use a dedicated entity manager (docs/doctrine.md).'], $rows);
    }

    public function testTheTablesOfTheHostApplicationAreNotChecked(): void
    {
        $harness = self::doctrine([]);
        $harness->createDatabaseSchema();
        $harness->privateService('doctrine.dbal.default_connection', Connection::class)->executeStatement('CREATE TABLE host_notes (id INTEGER PRIMARY KEY)');

        $command = $harness->command('urssaf:dpae:check');
        $status = $command->execute(['--skip-remote' => true]);

        static::assertSame(0, $status);
        static::assertContains(['database schema', 'default', 'ok', 'The package tables match the mapping.'], Output::rows($command->getDisplay()));
    }

    public function testADedicatedEntityManagerCanBeSelected(): void
    {
        $harness = Harness::boot(
            Simulated::config(['persistence' => ['driver' => 'doctrine', 'entity_manager' => 'urssaf'], 'outbox' => ['enabled' => true, 'sink' => 'test.sink']]),
            [
                'dbal' => ['connections' => ['default' => ['driver' => 'pdo_sqlite', 'memory' => true], 'urssaf' => ['driver' => 'pdo_sqlite', 'memory' => true]]],
                'orm' => [
                    ...LazyGhostOption::options(),
                    'entity_managers' => ['default' => ['connection' => 'default'], 'urssaf' => ['connection' => 'urssaf']],
                ],
            ],
            false,
            false,
        );

        $mapped = $harness->privateService('doctrine.orm.urssaf_entity_manager', EntityManagerInterface::class);
        $other = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);
        static::assertFalse($mapped->getMetadataFactory()->isTransient(DepositRecord::class));
        static::assertTrue($other->getMetadataFactory()->isTransient(DepositRecord::class));
        $check = $harness->command('urssaf:dpae:check');
        $check->execute(['--skip-remote' => true]);
        static::assertStringContainsString('| database schema', $check->getDisplay());
        static::assertStringNotContainsString('| entity manager', $check->getDisplay());
        static::assertInstanceOf(DoctrineOutbox::class, $harness->privateService(Outbox::class, Outbox::class));
    }

    public function testNothingIsMappedWhenAnotherDriverIsSelected(): void
    {
        $harness = Harness::boot(Simulated::config(['persistence' => ['driver' => 'in_memory']]), true, false, false);
        $entityManager = $harness->privateService('doctrine.orm.default_entity_manager', EntityManagerInterface::class);

        static::assertTrue($entityManager->getMetadataFactory()->isTransient(DepositRecord::class));
        static::assertNotInstanceOf(DoctrineDepositRepository::class, $harness->privateService(DepositRepository::class, DepositRepository::class));
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function doctrine(array $config): Harness
    {
        return Harness::boot(Simulated::config(['persistence' => ['driver' => 'doctrine'], ...$config]), true, false, false);
    }
}
