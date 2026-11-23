<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use PDO;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;

/**
 * Real entity managers on SQLite for the functional tests: an in-memory database, or a file shared by several entity
 * managers (each with its own unit of work and connection), built from the public integration API of the bridge.
 */
final class TestDatabase
{
    private function __construct() {}

    /**
     * Entity manager on a private in-memory database, schema created.
     *
     */
    public static function entityManager(): EntityManagerInterface
    {
        $entityManager = self::connect(['memory' => true], DoctrineIntegration::mappingDriver());
        self::createSchema($entityManager);

        return $entityManager;
    }

    /**
     * Entity manager on a new SQLite file, schema created.
     */
    public static function createFile(string $path): EntityManagerInterface
    {
        $entityManager = self::connect(['path' => $path], DoctrineIntegration::mappingDriver());
        self::createSchema($entityManager);

        return $entityManager;
    }

    /**
     * Another entity manager (own unit of work and connection) on an existing SQLite file.
     */
    public static function openFile(string $path): EntityManagerInterface
    {
        return self::connect(['path' => $path], DoctrineIntegration::mappingDriver());
    }

    public static function directory(): string
    {
        return \dirname(__DIR__, 4) . '/var/functional';
    }

    /**
     * @param array{memory: true}|array{path: string} $location
     */
    private static function connect(array $location, MappingDriver $driver): EntityManagerInterface
    {
        DoctrineIntegration::registerTypes();
        $configuration = new Configuration();
        $configuration->setMetadataDriverImpl($driver);
        $configuration->setProxyDir(self::directory() . '/proxies');
        $configuration->setProxyNamespace('UrssafDpaeTestProxies');
        if (DoctrineIntegration::nativeLazyObjectsAvailable()) {
            $configuration->enableNativeLazyObjects(true);
        }

        return new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'driverOptions' => [PDO::ATTR_TIMEOUT => 1]] + $location, $configuration), $configuration);
    }

    private static function createSchema(EntityManagerInterface $entityManager): void
    {
        (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }
}
