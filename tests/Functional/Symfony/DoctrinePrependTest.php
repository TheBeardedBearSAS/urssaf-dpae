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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\ExtensionConfigSnapshot;

/**
 * The "doctrine" configuration prepended by the bundle.
 */
final class DoctrinePrependTest extends TestCase
{
    public function testTheTypesAndTheMappingOfTheDefaultEntityManagerArePrepended(): void
    {
        $snapshot = new ExtensionConfigSnapshot('doctrine');

        (new TestKernel(['persistence' => ['driver' => 'doctrine']], true, false, false))->definitionsRecordedBy($snapshot);

        $prepended = $snapshot->configs()[0];
        static::assertSame(DoctrineIntegration::types(), self::section($prepended, 'dbal')['types'] ?? null);
        $orm = self::prependedOrm($snapshot);
        static::assertSame(DoctrineIntegration::nativeLazyObjectsAvailable(), \array_key_exists('enable_native_lazy_objects', $orm));
        static::assertArrayHasKey('mappings', $orm);
        static::assertArrayNotHasKey('entity_managers', $orm);
    }

    public function testTheMappingIsPrependedToTheSelectedEntityManagerOnly(): void
    {
        $snapshot = new ExtensionConfigSnapshot('doctrine');
        $doctrine = ['dbal' => ['driver' => 'pdo_sqlite', 'memory' => true], 'orm' => ['entity_managers' => ['urssaf' => ['connection' => 'default']]]];

        (new TestKernel(['persistence' => ['driver' => 'doctrine', 'entity_manager' => 'urssaf']], $doctrine, false, false))->definitionsRecordedBy($snapshot);

        $orm = self::prependedOrm($snapshot);
        static::assertSame(DoctrineIntegration::nativeLazyObjectsAvailable(), \array_key_exists('enable_native_lazy_objects', $orm));
        static::assertArrayNotHasKey('mappings', $orm);
        // @mago-expect analysis:mixed-assignment The recorded configuration is untyped; the assertion below narrows it.
        $managers = $orm['entity_managers'] ?? null;
        static::assertIsArray($managers);
        static::assertSame(['urssaf'], array_keys($managers));
    }

    public function testNothingIsPrependedWithAnotherDriver(): void
    {
        $snapshot = new ExtensionConfigSnapshot('doctrine');

        (new TestKernel(['persistence' => ['driver' => 'in_memory']], true, false, false))->definitionsRecordedBy($snapshot);

        static::assertCount(1, $snapshot->configs());
        static::assertArrayNotHasKey('types', self::section($snapshot->configs()[0], 'dbal'));
        static::assertArrayNotHasKey('mappings', self::section($snapshot->configs()[0], 'orm'));
    }

    /**
     * @param array<array-key, mixed> $config
     *
     * @return array<array-key, mixed>
     */
    private static function section(array $config, string $name): array
    {
        // @mago-expect analysis:mixed-assignment The recorded configuration is untyped; the assertion below narrows it.
        $section = $config[$name] ?? [];
        static::assertIsArray($section);

        return $section;
    }

    /**
     * @return array<array-key, mixed> the "orm" section prepended by the bundle (the first configuration recorded)
     */
    private static function prependedOrm(ExtensionConfigSnapshot $snapshot): array
    {
        // @mago-expect analysis:mixed-assignment The recorded configuration is untyped; the assertion below narrows it.
        $orm = $snapshot->configs()[0]['orm'] ?? null;
        static::assertIsArray($orm);

        return $orm;
    }
}
