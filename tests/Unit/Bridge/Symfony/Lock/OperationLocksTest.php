<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Lock;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Lock\LockFactory;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;

final class OperationLocksTest extends TestCase
{
    public function testTheResourceIsPrefixedByTheOperationAndTheScope(): void
    {
        static::assertSame('urssaf_dpae.sync-returns.tenant-1', OperationLocks::resource('sync-returns', ['tenant-1']));
    }

    public function testTheScopePartsAreJoinedWithoutAmbiguity(): void
    {
        static::assertSame('urssaf_dpae.deposit.tenant-1.ref-1', OperationLocks::resource('deposit', ['tenant-1', 'ref-1']));
        static::assertNotSame(OperationLocks::resource('deposit', ['a.b', 'c']), OperationLocks::resource('deposit', ['a', 'b.c']));
        static::assertSame('urssaf_dpae.deposit.a%2Eb.c', OperationLocks::resource('deposit', ['a.b', 'c']));
        static::assertNotSame(OperationLocks::resource('deposit', ['a%2Eb', 'c']), OperationLocks::resource('deposit', ['a.b', 'c']));
        static::assertSame('urssaf_dpae.deposit.a%252Eb.c', OperationLocks::resource('deposit', ['a%2Eb', 'c']));
    }

    public function testWithoutAFactoryTheOperationJustRuns(): void
    {
        $locks = new OperationLocks(null, 60.0);

        static::assertFalse($locks->isEnabled());
        static::assertSame('ran', $locks->exclusively('op', ['scope'], static fn(): string => 'ran', static fn(string $resource): string => 'busy ' . $resource));
    }

    public function testTheOperationRunsUnderTheLockAndReleasesIt(): void
    {
        $store = new SpyStore();
        $locks = new OperationLocks(new LockFactory($store), 60.0);

        $result = $locks->exclusively('op', ['scope'], $store->releases(...), static fn(): int => -1);

        static::assertTrue($locks->isEnabled());
        static::assertSame(0, $result);
        static::assertSame(1, $store->releases());
    }

    public function testTheLockIsReleasedWhenTheOperationFails(): void
    {
        $store = new SpyStore();
        $locks = new OperationLocks(new LockFactory($store), 60.0);

        try {
            $locks->exclusively('op', ['scope'], static fn(): never => throw new RuntimeException('boom'), static fn(): string => 'busy');
            static::fail('The failure must propagate.');
        } catch (RuntimeException $failure) {
            static::assertSame('boom', $failure->getMessage());
        }

        static::assertSame(1, $store->releases());
    }

    public function testTheLockExpiresAfterTheConfiguredTtl(): void
    {
        $store = new SpyStore();
        $locks = new OperationLocks(new LockFactory($store), 7.0);

        $locks->exclusively('op', ['scope'], static fn(): bool => true, static fn(): bool => false);

        static::assertSame([7.0], $store->ttls());
    }

    public function testABusyOperationIsReportedWithItsLockNameAndNeverWaitedFor(): void
    {
        $locks = new OperationLocks(new LockFactory(new BusyStore()), 60.0);

        $result = $locks->exclusively('op', ['scope'], static fn(): string => 'ran', static fn(string $resource): string => 'busy ' . $resource);

        static::assertSame('busy urssaf_dpae.op.scope', $result);
    }
}
