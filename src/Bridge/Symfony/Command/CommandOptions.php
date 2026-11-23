<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Integers;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The options shared by the console commands (--tenant, --limit, --dry-run, --mode) and their reading.
 *
 * @mago-expect lint:too-many-methods One small reader or declaration per option
 */
final class CommandOptions
{
    public static function addTenant(Command $command, string $description): void
    {
        $command->addOption('tenant', null, InputOption::VALUE_REQUIRED, $description);
    }

    public static function addLimit(Command $command, string $description): void
    {
        $command->addOption('limit', null, InputOption::VALUE_REQUIRED, $description);
    }

    public static function addDryRun(Command $command, string $description): void
    {
        $command->addOption('dry-run', null, InputOption::VALUE_NONE, $description);
    }

    public static function addMode(Command $command): void
    {
        $command->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Declaration mode of the deposit: "test" or "real" (required, never implied)');
    }

    public static function dryRun(InputInterface $input): bool
    {
        return $input->getOption('dry-run') === true;
    }

    /**
     * @throws InvalidOptionException when the option is missing, or not "test" nor "real"
     */
    public static function mode(InputInterface $input): DeclarationMode
    {
        $mode = self::text($input, 'mode') ?? throw new InvalidOptionException('The --mode option is required: "test" or "real".');

        return DeclarationMode::tryFrom($mode) ?? throw new InvalidOptionException('The --mode option must be "test" or "real".');
    }

    private static function tenant(InputInterface $input): ?TenantId
    {
        $tenant = self::text($input, 'tenant');

        return $tenant === null ? null : TenantId::fromString($tenant);
    }

    /**
     * The tenant given with --tenant, else the only configured tenant.
     *
     * @param list<string> $configured
     *
     * @throws InvalidOptionException when no tenant, or several, can be determined
     */
    public static function singleTenant(InputInterface $input, array $configured): TenantId
    {
        $tenant = self::tenant($input);
        if ($tenant instanceof TenantId) {
            return $tenant;
        }
        if (\count($configured) !== 1) {
            throw new InvalidOptionException('The --tenant option is required unless exactly one tenant is configured.');
        }

        return TenantId::fromString($configured[0]);
    }

    /**
     * The tenant given with --tenant, else the configured tenants.
     *
     * @param list<string> $configured
     *
     * @return non-empty-list<TenantId>
     *
     * @throws InvalidOptionException when no tenant can be determined
     */
    public static function tenantsOrConfigured(InputInterface $input, array $configured): array
    {
        $tenant = self::tenant($input);
        if ($tenant instanceof TenantId) {
            return [$tenant];
        }
        $tenants = array_map(TenantId::fromString(...), $configured);

        return $tenants === [] ? throw new InvalidOptionException('No tenant to process: use --tenant=<id> or configure "urssaf_dpae.tenants".') : $tenants;
    }

    /**
     * @param positive-int $default
     *
     * @return positive-int
     *
     * @throws InvalidOptionException for a value that is not a positive integer
     */
    public static function limit(InputInterface $input, int $default): int
    {
        $limit = self::text($input, 'limit');
        if ($limit === null) {
            return $default;
        }
        $parsed = Integers::parse($limit);

        if ($parsed === null || $parsed < 1) {
            throw new InvalidOptionException('The --limit option must be a positive integer.');
        }

        return $parsed;
    }

    /**
     * An ISO 8601 date-time with an offset ("2026-11-22T08:30:00+01:00").
     *
     * @throws InvalidOptionException when the value is not one
     */
    public static function instant(InputInterface $input, string $name): ?Instant
    {
        $value = self::text($input, $name);
        if ($value === null) {
            return null;
        }
        try {
            return Instant::fromIso8601($value);
        } catch (InvalidValue $failure) {
            throw new InvalidOptionException(\sprintf('The --%s option must be an ISO 8601 date-time with an offset, such as 2026-11-22T08:30:00+01:00.', $name), 0, $failure);
        }
    }

    /**
     * A positional argument as text ("" when absent).
     */
    public static function argument(InputInterface $input, string $name): string
    {
        // @mago-expect analysis:mixed-assignment Symfony arguments are untyped: the value is narrowed on the next line.
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }

    public static function text(InputInterface $input, string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment Symfony options are untyped: the value is narrowed on the next line.
        $value = $input->getOption($name);

        return \is_string($value) ? $value : null;
    }
}
