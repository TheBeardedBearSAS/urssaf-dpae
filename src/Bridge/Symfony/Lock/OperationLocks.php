<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock;

use Symfony\Component\Lock\LockFactory;

/**
 * Prevents overlapping runs of the same operation for the same tenant (overlapping crons, a cron racing a Messenger
 * worker...) with a non-blocking Symfony Lock named "urssaf_dpae.<operation>.<scope>" (ADR 0009). The scope is a list of
 * parts (the tenant, then the reference of a deposit...) joined by dots, and a part escapes "%" and "." ("%25", "%2E"):
 * two different scopes never give the same lock name (tenant "a.b" with reference "c" is not tenant "a" with reference "b.c").
 *
 * Without a lock factory (symfony/lock not installed, or the "lock" option disabled) every operation just runs: the
 * claims of the domain (state transitions, conditional updates) remain the last line of defence against a double deposit.
 */
final readonly class OperationLocks
{
    public const PREFIX = 'urssaf_dpae';

    public function __construct(
        private ?LockFactory $factory,
        private float $ttl,
    ) {}

    /**
     * False when the lock is disabled or symfony/lock is missing: every operation then runs unlocked.
     */
    public function isEnabled(): bool
    {
        return $this->factory instanceof LockFactory;
    }

    /**
     * @param list<string> $scope
     */
    public static function resource(string $operation, array $scope): string
    {
        return implode('.', [self::PREFIX, $operation, ...array_map(static fn(string $part): string => str_replace(['%', '.'], ['%25', '%2E'], $part), $scope)]);
    }

    /**
     * Runs $action while holding the lock of the operation, or $busy (given the lock name) when another process holds it.
     *
     * @template T
     * @template B
     *
     * @param list<string>        $scope
     * @param callable(): T       $action
     * @param callable(string): B $busy
     * @param-immediately-invoked-callable $action
     * @param-immediately-invoked-callable $busy
     *
     * @return T|B
     */
    public function exclusively(string $operation, array $scope, callable $action, callable $busy): mixed
    {
        if (!$this->factory instanceof LockFactory) {
            return $action();
        }
        $resource = self::resource($operation, $scope);
        $lock = $this->factory->createLock($resource, $this->ttl);
        if (!$lock->acquire(false)) {
            return $busy($resource);
        }
        try {
            return $action();
        } finally {
            $lock->release();
        }
    }
}
