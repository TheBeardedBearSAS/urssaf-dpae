<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine;

use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use ReflectionClass;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\BytesType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositResultType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\ExternalReferenceType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FingerprintType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FlowIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;

/**
 * Everything a host application needs to persist the deposits, the returns and the outbox with Doctrine ORM 3 / DBAL 4.
 *
 * The domain model carries no Doctrine metadata: the mapping lives in XML files (Resources/mapping) whose names follow
 * the Symfony "simplified" convention for the namespace prefix {@see self::MAPPING_NAMESPACE}. A plain application does
 *
 *     DoctrineIntegration::registerTypes();
 *     $configuration->setMetadataDriverImpl(DoctrineIntegration::mappingDriver());
 *
 * and a Symfony application prepends {@see self::doctrineBundleConfiguration()} to the "doctrine" extension, naming the
 * dedicated entity manager of the package (`doctrineBundleConfigurationFor('urssaf')`) when it has one.
 *
 * @mago-expect lint:too-many-methods The public facade of the bridge: mapping, types, native lazy objects and the four forms of the bundle configuration
 */
final class DoctrineIntegration
{
    /**
     * Namespace prefix of the mapped classes (the bridge entities, one per table): file "Bridge.Doctrine.Entity.DepositRecord.orm.xml" maps
     * "<prefix>\Bridge\Doctrine\Entity\DepositRecord".
     */
    public const MAPPING_NAMESPACE = 'TheBeardedBear\UrssafDpae';

    public const MAPPING_ALIAS = 'UrssafDpae';

    /**
     * Prefix of the tables created by the mapping.
     */
    public const TABLE_PREFIX = 'urssaf_dpae_';

    public static function mappingDirectory(): string
    {
        return __DIR__ . '/Resources/mapping';
    }

    /**
     * Metadata driver for the mapping files, for applications that configure the ORM without the Symfony bundle.
     */
    public static function mappingDriver(): SimplifiedXmlDriver
    {
        return new SimplifiedXmlDriver([self::mappingDirectory() => self::MAPPING_NAMESPACE]);
    }

    /**
     * The custom DBAL types of the mapping, by registration name.
     *
     * @return array<non-empty-string, class-string<Type>>
     */
    public static function types(): array
    {
        return [
            BytesType::NAME => BytesType::class,
            DepositIdType::NAME => DepositIdType::class,
            DepositResultType::NAME => DepositResultType::class,
            ExternalReferenceType::NAME => ExternalReferenceType::class,
            FingerprintType::NAME => FingerprintType::class,
            FlowIdType::NAME => FlowIdType::class,
            InstantType::NAME => InstantType::class,
            SiretType::NAME => SiretType::class,
            TenantIdType::NAME => TenantIdType::class,
        ];
    }

    /**
     * Registers the custom types in the global DBAL registry. Idempotent.
     */
    public static function registerTypes(): void
    {
        foreach (self::types() as $name => $class) {
            if (Type::hasType($name)) {
                continue;
            }
            Type::addType($name, $class);
        }
    }

    /**
     * Whether native lazy objects (PHP 8.4 lazy objects, used instead of generated proxies) can be enabled: they need
     * PHP 8.4 and an ORM that implements them (3.4+).
     */
    public static function nativeLazyObjectsAvailable(): bool
    {
        return self::runtimeNativeLazyObjectsOptions() !== [];
    }

    /**
     * The "orm" options that enable the native lazy objects, or none when the runtime cannot use them.
     *
     * @param class-string $ormConfiguration the ORM configuration class, which has "enableNativeLazyObjects()" from ORM 3.4
     *
     * @return array{enable_native_lazy_objects?: true}
     */
    public static function nativeLazyObjectsOptions(int $phpVersionId, string $ormConfiguration): array
    {
        if ($phpVersionId >= 80_400 && (new ReflectionClass($ormConfiguration))->hasMethod('enableNativeLazyObjects')) {
            return ['enable_native_lazy_objects' => true];
        }

        return [];
    }

    /**
     * Configuration to prepend to the "doctrine" extension of doctrine-bundle: the types, the XML mapping of the default
     * entity manager and, when PHP and the ORM support them, the native lazy objects (symfony/var-exporter dropped the lazy
     * ghosts the ORM proxies rely on in version 8). The host can still override it in its own "doctrine" configuration.
     *
     * @return array{
     *     dbal: array{types: array<non-empty-string, class-string<Type>>},
     *     orm: array{
     *         enable_native_lazy_objects?: true,
     *         mappings?: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>,
     *         entity_managers?: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>
     *     }
     * }
     */
    public static function doctrineBundleConfiguration(): array
    {
        return self::doctrineBundleConfigurationWith(self::runtimeNativeLazyObjectsOptions());
    }

    /**
     * The same configuration, with the mapping on the entity manager `$entityManager` (the dedicated one recommended in
     * docs/doctrine.md) instead of the default one: under `orm.entity_managers.<name>.mappings`.
     *
     * @param non-empty-string $entityManager
     *
     * @return array{
     *     dbal: array{types: array<non-empty-string, class-string<Type>>},
     *     orm: array{
     *         enable_native_lazy_objects?: true,
     *         mappings?: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>,
     *         entity_managers?: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>
     *     }
     * }
     */
    public static function doctrineBundleConfigurationFor(string $entityManager): array
    {
        return self::doctrineBundleConfigurationForWith($entityManager, self::runtimeNativeLazyObjectsOptions());
    }

    /**
     * The configuration to prepend, with the given options on top of the "orm" section.
     *
     * @param array{enable_native_lazy_objects?: true} $ormOptions
     *
     * @return array{
     *     dbal: array{types: array<non-empty-string, class-string<Type>>},
     *     orm: array{
     *         enable_native_lazy_objects?: true,
     *         mappings?: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>,
     *         entity_managers?: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>
     *     }
     * }
     */
    public static function doctrineBundleConfigurationWith(array $ormOptions): array
    {
        return self::configuration($ormOptions, ['mappings' => self::mappings()]);
    }

    /**
     * {@see self::doctrineBundleConfigurationWith()} with the mapping on the entity manager `$entityManager`.
     *
     * @param non-empty-string                         $entityManager
     * @param array{enable_native_lazy_objects?: true} $ormOptions
     *
     * @return array{
     *     dbal: array{types: array<non-empty-string, class-string<Type>>},
     *     orm: array{
     *         enable_native_lazy_objects?: true,
     *         mappings?: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>,
     *         entity_managers?: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>
     *     }
     * }
     */
    public static function doctrineBundleConfigurationForWith(string $entityManager, array $ormOptions): array
    {
        return self::configuration($ormOptions, ['entity_managers' => [$entityManager => ['mappings' => self::mappings()]]]);
    }

    /**
     * @param array{enable_native_lazy_objects?: true}                                                                                                                                                                                                                                 $ormOptions
     * @param array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}|array{entity_managers: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>} $mapped
     *
     * @return array{
     *     dbal: array{types: array<non-empty-string, class-string<Type>>},
     *     orm: array{
     *         enable_native_lazy_objects?: true,
     *         mappings?: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>,
     *         entity_managers?: array<string, array{mappings: array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>}>
     *     }
     * }
     */
    private static function configuration(array $ormOptions, array $mapped): array
    {
        return ['dbal' => ['types' => self::types()], 'orm' => $ormOptions + $mapped];
    }

    /**
     * @return array<string, array{type: string, is_bundle: bool, dir: string, prefix: string, alias: string}>
     */
    private static function mappings(): array
    {
        return [
            self::MAPPING_ALIAS => [
                'type' => 'xml',
                'is_bundle' => false,
                'dir' => self::mappingDirectory(),
                'prefix' => self::MAPPING_NAMESPACE,
                'alias' => self::MAPPING_ALIAS,
            ],
        ];
    }

    /**
     * @return array{enable_native_lazy_objects?: true}
     */
    private static function runtimeNativeLazyObjectsOptions(): array
    {
        return self::nativeLazyObjectsOptions(\PHP_VERSION_ID, Configuration::class);
    }
}
