<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Persistence\InMemory;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * The optimistic lock, the attempt budget of the claim and the batches of the in-memory deposit repository.
 */
final class InMemoryDepositRepositoryVersionTest extends TestCase
{
    private static function deposit(string $id, string $claimedAt): Deposit
    {
        return Samples::deposit($id, 't1', 'ref-' . $id, $id, $claimedAt);
    }

    public function testEverySaveMovesTheVersionOfTheAggregate(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', Samples::NOW);

        static::assertSame(0, $deposit->version());
        $repository->save($deposit);
        static::assertSame(1, $deposit->version());
        $repository->save($deposit);
        static::assertSame(2, $deposit->version());
    }

    public function testACopyReadBeforeAnotherSaveCannotBeSavedAnyMore(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', Samples::NOW);
        $repository->save($deposit);
        $stale = clone $deposit;
        $repository->save($deposit);

        $failure = Outcome::of(static fn() => $repository->save($stale));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame('deposit.concurrent_modification', $failure->violationCode());
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'd1'], $failure->params());
    }

    public function testAClaimMovesTheStoredVersionToo(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', Samples::NOW);
        $repository->save($deposit);
        $beforeTheClaim = clone $deposit;

        static::assertTrue($repository->claim($deposit->id(), Samples::at(Samples::NOW), 3));

        static::assertSame(2, $deposit->version());
        static::assertInstanceOf(ConcurrentModification::class, Outcome::of(static fn() => $repository->save($beforeTheClaim)));
    }

    public function testAClaimIsRefusedWhenTheAttemptsAreExhausted(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', Samples::NOW);
        $repository->save($deposit);

        static::assertFalse($repository->claim($deposit->id(), Samples::at(Samples::NOW), 0));
        static::assertSame(0, $deposit->attempts());
    }

    public function testTheOldestClaimsComeFirstAndOnlyUpToTheLimit(): void
    {
        $repository = new InMemoryDepositRepository();
        foreach (['c' => '2022-02-01T10:03:00Z', 'a' => '2022-02-01T10:01:00Z', 'b' => '2022-02-01T10:02:00Z', 'd' => '2022-02-01T10:01:00Z'] as $id => $claimedAt) {
            $deposit = self::deposit($id, $claimedAt);
            $repository->save($deposit);
            $repository->claim($deposit->id(), Samples::at($claimedAt), 3);
        }
        $ids = static fn(array $deposits): array => array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $deposits);

        static::assertSame(['a', 'd', 'b'], $ids($repository->withoutFlowId(Samples::tenant('t1'), 3)));
        static::assertSame(['a', 'd'], $ids($repository->staleDepositing(Samples::tenant('t1'), Samples::at('2022-02-01T10:02:00Z'), 5)));
        static::assertSame(['a'], $ids($repository->staleDepositing(Samples::tenant('t1'), Samples::at('2022-02-01T10:02:00Z'), 1)));
    }
}
