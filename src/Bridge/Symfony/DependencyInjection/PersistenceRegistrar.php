<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositFlowStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * Repositories, content store and transaction manager of the selected persistence driver.
 */
final readonly class PersistenceRegistrar
{
    public function __construct(private ServicesConfigurator $services) {}

    /**
     * @param array{driver: string, deposit_repository: ?string, return_repository: ?string, sync_cursor_repository: ?string, return_content_store: ?string, transaction_manager: ?string, entity_manager: string, keep_flows: bool} $persistence
     */
    public function register(array $persistence): void
    {
        if ($persistence['driver'] === BundleConfiguration::DRIVER_DOCTRINE) {
            $entityManager = new Reference(self::entityManagerId($persistence['entity_manager']));
            $this->registerDoctrine($entityManager);
            if ($persistence['keep_flows']) {
                $this->services->set(DoctrineDepositFlowStore::class)->args([$entityManager])->public();
            }

            return;
        }
        if ($persistence['driver'] === BundleConfiguration::DRIVER_CUSTOM) {
            $this->services->alias(DepositRepository::class, (string) $persistence['deposit_repository']);
            $this->services->alias(ReturnRepository::class, (string) $persistence['return_repository']);
            $this->services->alias(SyncCursorRepository::class, (string) $persistence['sync_cursor_repository']);
            $this->services->alias(ReturnContentStore::class, (string) $persistence['return_content_store']);
            $this->services->alias(TransactionManager::class, (string) $persistence['transaction_manager']);

            return;
        }
        $this->services->set(DepositRepository::class, InMemoryDepositRepository::class);
        $this->services->set(ReturnRepository::class, InMemoryReturnRepository::class);
        $this->services->set(SyncCursorRepository::class, InMemorySyncCursorRepository::class);
        $this->services->set(ReturnContentStore::class, InMemoryReturnContentStore::class);
        $this->services->set(TransactionManager::class, ImmediateTransactionManager::class);
    }

    /**
     * The id DoctrineBundle gives the entity manager of that name.
     */
    public static function entityManagerId(string $name): string
    {
        return \sprintf('doctrine.orm.%s_entity_manager', $name);
    }

    private function registerDoctrine(Reference $entityManager): void
    {
        $this->services->set(DepositRepository::class, DoctrineDepositRepository::class)->args([$entityManager]);
        $this->services->set(ReturnRepository::class, DoctrineReturnRepository::class)->args([$entityManager]);
        $this->services->set(SyncCursorRepository::class, DoctrineSyncCursorRepository::class)->args([$entityManager]);
        $this->services->set(ReturnContentStore::class, DoctrineReturnContentStore::class)->args([$entityManager, new Reference(Clock::class)]);
        $this->services->set(TransactionManager::class, DoctrineTransactionManager::class)->args([$entityManager]);
    }
}
