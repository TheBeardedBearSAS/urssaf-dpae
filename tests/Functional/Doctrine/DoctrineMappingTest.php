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

use Doctrine\DBAL\Schema\Index\IndexedColumn;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;

final class DoctrineMappingTest extends TestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
    }

    public function testTheMappingIsValidAndInSyncWithTheSchema(): void
    {
        $validator = new SchemaValidator($this->entityManager);

        static::assertSame([], $validator->validateMapping());
        static::assertTrue($validator->schemaInSyncWithMetadata());
    }

    public function testTheProxiesOfTheMappedEntitiesCanBePreGenerated(): void
    {
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();

        $generated = $this->entityManager->getProxyFactory()->generateProxyClasses($metadata);

        static::assertCount(6, $metadata);
        static::assertGreaterThanOrEqual(0, $generated);
    }

    public function testTheTablesAreTheOnesOfTheAdr(): void
    {
        $manager = $this->entityManager->getConnection()->createSchemaManager();
        $tables = array_map(static fn(Table $table): string => $table->getObjectName()->getUnqualifiedName()->getValue(), $manager->introspectTables());
        sort($tables);

        static::assertSame(
            [
                'urssaf_dpae_deposit_flows',
                'urssaf_dpae_deposits',
                'urssaf_dpae_outbox',
                'urssaf_dpae_return_contents',
                'urssaf_dpae_returns',
                'urssaf_dpae_sync_cursors',
            ],
            $tables,
        );
    }

    public function testTheConstraintsAndTheIndexesAreTheOnesOfTheAdr(): void
    {
        $manager = $this->entityManager->getConnection()->createSchemaManager();

        static::assertSame(
            [
                'idx_urssaf_dpae_deposit_claim' => [false, ['tenant_id', 'state', 'claimed_at']],
                'idx_urssaf_dpae_deposit_reference' => [false, ['tenant_id', 'external_reference']],
                'idx_urssaf_dpae_deposit_state' => [false, ['tenant_id', 'state', 'updated_at']],
                'uniq_urssaf_dpae_deposit_flow_id' => [true, ['tenant_id', 'flow_id']],
                'uniq_urssaf_dpae_deposit_open_fingerprint' => [true, ['tenant_id', 'open_fingerprint']],
                'uniq_urssaf_dpae_deposit_open_reference' => [true, ['tenant_id', 'open_reference']],
            ],
            self::indexes($manager->introspectTableByUnquotedName('urssaf_dpae_deposits')),
        );
        static::assertSame(
            [
                'idx_urssaf_dpae_return_download' => [false, ['tenant_id', 'download_state', 'publication_at']],
                'idx_urssaf_dpae_return_first_seen' => [false, ['tenant_id', 'first_seen_at']],
                'idx_urssaf_dpae_return_publication' => [false, ['tenant_id', 'publication_at']],
                'uniq_urssaf_dpae_return_identity' => [true, ['tenant_id', 'flow_id', 'nature', 'has_ops', 'ops']],
            ],
            self::indexes($manager->introspectTableByUnquotedName('urssaf_dpae_returns')),
        );
        static::assertSame(
            ['idx_urssaf_dpae_return_content_downloaded' => [false, ['tenant_id', 'downloaded_at']]],
            self::indexes($manager->introspectTableByUnquotedName('urssaf_dpae_return_contents')),
        );
        static::assertSame(
            [
                'idx_urssaf_dpae_outbox_pending' => [false, ['dispatched_at', 'sequence']],
                'uniq_urssaf_dpae_outbox_event_id' => [true, ['event_id']],
            ],
            self::indexes($manager->introspectTableByUnquotedName('urssaf_dpae_outbox')),
        );
    }

    public function testNothingOfTheSchemaOrTheTypesCanCollideWithAnotherPackage(): void
    {
        $manager = $this->entityManager->getConnection()->createSchemaManager();
        foreach ($manager->introspectTables() as $table) {
            static::assertStringStartsWith('urssaf_dpae_', $table->getObjectName()->getUnqualifiedName()->getValue());
            foreach ($table->getIndexes() as $index) {
                $name = $index->getObjectName()->getIdentifier()->getValue();
                static::assertTrue(
                    str_starts_with($name, 'idx_urssaf_dpae_') || str_starts_with($name, 'uniq_urssaf_dpae_') || str_starts_with($name, 'primary') || str_starts_with($name, 'sqlite_autoindex_urssaf_dpae_'),
                    $name,
                );
            }
        }
        foreach (array_keys(DoctrineIntegration::types()) as $name) {
            static::assertStringStartsWith('urssaf_dpae_', $name);
        }
        static::assertTrue(Type::hasType('urssaf_dpae_instant'));
    }

    /**
     * @return array<string, array{bool, list<string>}> unique flag and columns by index name
     */
    private static function indexes(Table $table): array
    {
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            $name = $index->getObjectName()->getIdentifier()->getValue();
            if (str_starts_with($name, 'idx_') || str_starts_with($name, 'uniq_')) {
                $indexes[$name] = [
                    $index->getType() === IndexType::UNIQUE,
                    array_map(static fn(IndexedColumn $column): string => $column->getColumnName()->getIdentifier()->getValue(), $index->getIndexedColumns()),
                ];
            }
        }
        ksort($indexes);

        return $indexes;
    }
}
