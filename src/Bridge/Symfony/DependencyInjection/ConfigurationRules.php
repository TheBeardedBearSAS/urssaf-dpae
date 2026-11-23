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

use DateInterval;
use DateTimeZone;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use Throwable;

/**
 * Validation predicates of the configuration tree (each one is true when the value is invalid).
 *
 * One small predicate per rule of the tree, hence the method count.
 *
 * @mago-expect lint:too-many-methods
 */
final class ConfigurationRules
{
    /**
     * @param array<array-key, mixed> $value
     */
    public static function hasBothCredentialSources(array $value): bool
    {
        /** @var array{credentials: array<array-key, mixed>, credentials_provider: ?string} $config */
        $config = $value;

        return $config['credentials'] !== [] && $config['credentials_provider'] !== null;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function hasNoCredentialSource(array $value): bool
    {
        /** @var array{credentials: array<array-key, mixed>, credentials_provider: ?string} $config */
        $config = $value;

        return $config['credentials'] === [] && $config['credentials_provider'] === null;
    }

    /**
     * The simulator refuses the real mode (ADR 0003): a literal `real` is a build error, an `%env()%` placeholder is
     * checked when the service is created ({@see SimulatedGatewayFactory}).
     *
     * @param array<array-key, mixed> $value
     */
    public static function simulatedGatewayInRealMode(array $value): bool
    {
        /** @var array{declaration_mode: string, gateway: array{simulated: bool}} $config */
        $config = $value;

        return $config['gateway']['simulated'] && $config['declaration_mode'] === DeclarationMode::Real->value;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function lockEnabledWithoutComponent(array $value): bool
    {
        return self::lockRequiredButMissing($value, class_exists(LockFactory::class));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function lockRequiredButMissing(array $value, bool $componentInstalled): bool
    {
        /** @var array{lock: array{enabled: bool|string}} $config */
        $config = $value;

        return $config['lock']['enabled'] === true && !$componentInstalled;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function schedulerEnabledWithoutComponent(array $value): bool
    {
        return self::schedulerRequiredButMissing($value, interface_exists(ScheduleProviderInterface::class));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function schedulerRequiredButMissing(array $value, bool $componentInstalled): bool
    {
        /** @var array{scheduler: array{enabled: bool}} $config */
        $config = $value;

        return $config['scheduler']['enabled'] && !$componentInstalled;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function outboxWithoutStorage(array $value): bool
    {
        /** @var array{outbox: array{enabled: bool, service: ?string}, persistence: array{driver: string}} $config */
        $config = $value;

        return $config['outbox']['enabled'] && $config['outbox']['service'] === null
            && $config['persistence']['driver'] === BundleConfiguration::DRIVER_CUSTOM;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function customDriverWithoutServices(array $value): bool
    {
        /** @var array{driver: string, deposit_repository: ?string, return_repository: ?string, sync_cursor_repository: ?string, return_content_store: ?string, transaction_manager: ?string} $persistence */
        $persistence = $value;

        return $persistence['driver'] === BundleConfiguration::DRIVER_CUSTOM
            && \in_array(null, [
                $persistence['deposit_repository'],
                $persistence['return_repository'],
                $persistence['sync_cursor_repository'],
                $persistence['return_content_store'],
                $persistence['transaction_manager'],
            ], true);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function keepFlowsWithoutDoctrine(array $value): bool
    {
        /** @var array{driver: string, keep_flows: bool} $persistence */
        $persistence = $value;

        return $persistence['keep_flows'] && $persistence['driver'] !== BundleConfiguration::DRIVER_DOCTRINE;
    }

    /**
     * The two indicators must differ, or the mode guard of the flow would be void (ADR 0003). The empty string is the dummy
     * value of an environment variable placeholder while the container is validated: it is checked at runtime instead.
     *
     * @param array<array-key, mixed> $value
     */
    public static function indicatorsNotDistinct(array $value): bool
    {
        /** @var array{real: string, test: string} $indicators */
        $indicators = $value;

        return $indicators['real'] !== '' && $indicators['real'] === $indicators['test'];
    }

    /**
     * The empty string is the dummy value of an environment variable placeholder while the container is validated (a
     * literal empty string cannot be told apart): an empty variable fails at runtime instead, with a clear message
     * ({@see InstallationModeFactory::create()}).
     */
    public static function isUnknownDeclarationMode(mixed $mode): bool
    {
        return !\in_array($mode, ['', ...array_map(static fn(DeclarationMode $case): string => $case->value, DeclarationMode::cases())], true);
    }

    public static function isUnknownTimeZone(mixed $zone): bool
    {
        return !\is_string($zone) || !\in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    public static function isInvalidOptionalInterval(mixed $value): bool
    {
        return $value !== null && self::isInvalidInterval($value);
    }

    public static function isInvalidInterval(mixed $value): bool
    {
        try {
            new DateInterval(\is_string($value) ? $value : '');
        } catch (Throwable) {
            return true;
        }

        return false;
    }
}
