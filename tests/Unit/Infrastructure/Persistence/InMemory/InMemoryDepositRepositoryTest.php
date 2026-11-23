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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class InMemoryDepositRepositoryTest extends TestCase
{
    private const T0 = '2022-02-01T10:00:00Z';
    private const T1 = '2022-02-01T10:10:00Z';

    private static function deposit(string $id, string $tenant, string $reference, string $variant): Deposit
    {
        return Samples::deposit($id, $tenant, $reference, $variant, self::T0);
    }

    public function testASavedDepositIsReadBackByIdAndAnUnknownOneIsNotFound(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);

        static::assertSame($deposit, $repository->get(DepositId::fromString('d1')));

        try {
            $repository->get(DepositId::fromString('missing'));
            static::fail('RecordNotFound expected.');
        } catch (RecordNotFound $e) {
            static::assertSame('deposit.not_found', $e->violationCode());
            static::assertSame('missing', $e->identifier());
        }
    }

    public function testAnOpenDepositIsFoundByReferenceWithinItsTenantOnly(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);
        $repository->save(self::deposit('d2', 't2', 'r1', 'b'));

        static::assertSame($deposit, $repository->findOpenByReference(Samples::tenant('t1'), Samples::reference('r1')));
        static::assertNull($repository->findOpenByReference(Samples::tenant('t1'), Samples::reference('other')));
        static::assertNull($repository->findOpenByReference(Samples::tenant('t3'), Samples::reference('r1')));
    }

    public function testAnOpenDepositIsFoundByFingerprintWithinItsTenantOnly(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);
        $repository->save(self::deposit('d2', 't2', 'r2', 'b'));

        static::assertSame($deposit, $repository->findOpenByFingerprint(Samples::tenant('t1'), Samples::fingerprint('a')));
        static::assertNull($repository->findOpenByFingerprint(Samples::tenant('t1'), Samples::fingerprint('b')));
        static::assertNull($repository->findOpenByFingerprint(Samples::tenant('t2'), Samples::fingerprint('a')));
    }

    public function testAClosedDepositIsNotOpenAnyMore(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);
        $repository->claim($deposit->id(), Samples::at(self::T1), 3);
        $deposit->markFailed('x', Samples::at(self::T1));

        static::assertNull($repository->findOpenByReference(Samples::tenant('t1'), Samples::reference('r1')));
        static::assertNull($repository->findOpenByFingerprint(Samples::tenant('t1'), Samples::fingerprint('a')));
        static::assertSame($deposit, $repository->get($deposit->id()));
    }

    public function testADepositIsFoundByFlowIdWithinItsTenantWhateverItsState(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);
        $repository->save(self::deposit('d2', 't1', 'r2', 'b'));
        $repository->claim($deposit->id(), Samples::at(self::T1), 3);
        $deposit->recordResult(SampleAnswers::rejection('F1'), Samples::at(self::T1));

        static::assertSame($deposit, $repository->findByFlowId(Samples::tenant('t1'), Samples::flowId('F1')));
        static::assertNull($repository->findByFlowId(Samples::tenant('t1'), Samples::flowId('F2')));
        static::assertNull($repository->findByFlowId(Samples::tenant('t2'), Samples::flowId('F1')));
    }

    public function testOnlyAPendingDepositCanBeClaimedAndOnlyOnce(): void
    {
        $repository = new InMemoryDepositRepository();
        $deposit = self::deposit('d1', 't1', 'r1', 'a');
        $repository->save($deposit);

        static::assertTrue($repository->claim($deposit->id(), Samples::at(self::T1), 3));
        static::assertFalse($repository->claim($deposit->id(), Samples::at(self::T1), 3));
        static::assertSame(DepositState::Depositing, $deposit->state());
        static::assertSame(1, $deposit->attempts());
        static::assertSame(self::T1, $deposit->claimedAt()->toIso8601());
    }

    public function testClaimingAnUnknownDepositIsNotFound(): void
    {
        $this->expectException(RecordNotFound::class);

        (new InMemoryDepositRepository())->claim(DepositId::fromString('missing'), Samples::at(self::T1), 3);
    }

    public function testDepositsWithoutFlowIdAreTheOnesSentOrPossiblySentOfTheTenant(): void
    {
        $repository = new InMemoryDepositRepository();
        $pending = self::deposit('pending', 't1', 'r1', 'a');
        $depositing = self::deposit('depositing', 't1', 'r2', 'b');
        $unknown = self::deposit('unknown', 't1', 'r3', 'c');
        $received = self::deposit('received', 't1', 'r4', 'd');
        $rejected = self::deposit('rejected', 't1', 'r5', 'e');
        $failed = self::deposit('failed', 't1', 'r6', 'f');
        $withFlow = self::deposit('with-flow', 't1', 'r7', 'g');
        $other = self::deposit('other', 't2', 'r8', 'h');
        foreach ([$pending, $depositing, $unknown, $received, $rejected, $failed, $withFlow, $other] as $deposit) {
            $repository->save($deposit);
        }
        $now = Samples::at(self::T1);
        foreach ([$depositing, $unknown, $received, $rejected, $failed, $withFlow, $other] as $deposit) {
            $repository->claim($deposit->id(), $now, 3);
        }
        $unknown->markOutcomeUnknown('x', $now);
        $received->recordResult(SampleAnswers::receiptWithoutFlowId(), $now);
        $rejected->recordResult(SampleAnswers::rejectionWithoutFlowId(), $now);
        $failed->markFailed('x', $now);
        $withFlow->recordResult(SampleAnswers::receipt('F1'), $now);

        $found = $repository->withoutFlowId(Samples::tenant('t1'), 100);

        static::assertSame(['depositing', 'received', 'rejected', 'unknown'], array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $found));
        static::assertSame(['other'], array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $repository->withoutFlowId(Samples::tenant('t2'), 100)));
    }

    public function testStaleDepositsAreTheOnesClaimedStrictlyBeforeTheInstant(): void
    {
        $repository = new InMemoryDepositRepository();
        $old = self::deposit('old', 't1', 'r1', 'a');
        $edge = self::deposit('edge', 't1', 'r2', 'b');
        $recent = self::deposit('recent', 't1', 'r3', 'c');
        $answered = self::deposit('answered', 't1', 'r4', 'd');
        $other = self::deposit('other', 't2', 'r5', 'e');
        foreach ([$old, $edge, $recent, $answered, $other] as $deposit) {
            $repository->save($deposit);
        }
        $repository->claim($old->id(), Samples::at('2022-02-01T10:00:00Z'), 3);
        $repository->claim($edge->id(), Samples::at('2022-02-01T10:05:00Z'), 3);
        $repository->claim($recent->id(), Samples::at('2022-02-01T10:05:01Z'), 3);
        $repository->claim($answered->id(), Samples::at('2022-02-01T10:00:00Z'), 3);
        $repository->claim($other->id(), Samples::at('2022-02-01T10:00:00Z'), 3);
        $answered->recordResult(SampleAnswers::receipt('F1'), Samples::at('2022-02-01T10:01:00Z'));

        $stale = $repository->staleDepositing(Samples::tenant('t1'), Samples::at('2022-02-01T10:05:00Z'), 100);

        static::assertSame(['old'], array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $stale));
    }
}
