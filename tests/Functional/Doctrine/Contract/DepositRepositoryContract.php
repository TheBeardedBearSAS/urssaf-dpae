<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * Behaviour shared by every {@see DepositRepository} adapter (ADR 0008): the in-memory one and the Doctrine one pass the
 * same tests. A test always reads a deposit again after a save or a claim: the in-memory adapter keeps the aggregate
 * itself, a database adapter builds a new one.
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 * @mago-expect lint:too-many-methods One method per rule of the repository contract
 */
trait DepositRepositoryContract
{
    private const ALL_STATES = ['pending', 'depositing', 'received', 'rejected', 'failed', 'invalid_credentials', 'outcome_unknown', 'not_received'];

    abstract protected function createDeposits(): DepositRepository;

    /**
     * @return array<string, array{Deposit}>
     */
    public static function storedDeposits(): array
    {
        return [
            'pending' => [Cases::pending()],
            'depositing' => [Cases::depositing()],
            'received with every field' => [Cases::received()],
            'received without flow id' => [Cases::receivedWithoutFlowId()],
            'rejected with reasons' => [Cases::rejected()],
            'failed' => [Cases::failed()],
            'back to pending after a send that did not happen' => [Cases::notSent()],
            'invalid credentials' => [Cases::invalidCredentials()],
            'outcome unknown' => [Cases::outcomeUnknown()],
            'adopted by the reconciliation' => [Cases::adopted()],
            'not received' => [Cases::notReceived()],
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function states(): iterable
    {
        foreach (self::ALL_STATES as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('storedDeposits')]
    public function testASavedDepositIsReadBackIdentically(Deposit $deposit): void
    {
        $deposits = $this->createDeposits();
        $deposits->save($deposit);

        $read = $deposits->get($deposit->id());

        static::assertEquals($deposit, $read);
        static::assertSame($deposit->state(), $read->state());
        static::assertSame($deposit->attempts(), $read->attempts());
        static::assertSame($deposit->adoptedByReconciliation(), $read->adoptedByReconciliation());
        static::assertSame($deposit->lastErrorCode(), $read->lastErrorCode());
        static::assertSame([], $read->releaseEvents());
    }

    public function testASavedDepositKeepsItsResultDocumentByteForByte(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::rejected();
        $deposits->save($deposit);

        $result = $deposits->get($deposit->id())->result();

        static::assertNotNull($result);
        static::assertSame(Cases::document()->bytes(), $result->document()->bytes());
        static::assertSame('application/xml; charset=ISO-8859-1', $result->document()->contentType());
    }

    public function testEverySaveMovesTheVersionByOneTheFirstOneIncluded(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::pending();

        static::assertSame(0, $deposit->version());
        $deposits->save($deposit);
        static::assertSame(1, $deposit->version());
        static::assertSame(1, $deposits->get($deposit->id())->version());

        $deposits->save($deposit);
        static::assertSame(2, $deposit->version());
        static::assertSame(2, $deposits->get($deposit->id())->version());
    }

    public function testAChangeIsStoredWhenTheDepositIsSavedAgain(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::pending());
        $deposit = $deposits->get(Cases::depositId('dep-a'));
        $deposit->startDeposit(Samples::at(Cases::T1), 3);
        $deposits->save($deposit);

        $read = $deposits->get($deposit->id());

        static::assertSame(DepositState::Depositing, $read->state());
        static::assertSame(1, $read->attempts());
        static::assertSame(2, $read->version());
    }

    public function testAnUnknownDepositIsNotFound(): void
    {
        $failure = Outcome::of(fn() => $this->createDeposits()->get(Cases::depositId('dep-missing')));

        static::assertInstanceOf(RecordNotFound::class, $failure);
        static::assertSame('dep-missing', $failure->identifier());
        static::assertSame('deposit', $failure->recordType());
    }

    public function testACopyReadBeforeAnotherSaveCannotBeSavedAnyMore(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::pending();
        $deposits->save($deposit);
        $stale = clone $deposit;
        $deposits->save($deposit);

        $failure = Outcome::of(static fn() => $deposits->save($stale));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame('deposit.concurrent_modification', $failure->violationCode());
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'dep-a'], $failure->params());
        static::assertSame(2, $deposits->get($deposit->id())->version());
    }

    public function testAfterALostRaceTheDepositIsReadAgainAndSaved(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::pending();
        $deposits->save($deposit);
        $stale = clone $deposit;
        $deposits->save($deposit);
        Outcome::of(static fn() => $deposits->save($stale));

        $fresh = $deposits->get($deposit->id());
        $fresh->startDeposit(Samples::at(Cases::T1), 3);
        $deposits->save($fresh);

        static::assertSame(DepositState::Depositing, $deposits->get($deposit->id())->state());
        static::assertSame(3, $deposits->get($deposit->id())->version());
    }

    public function testASecondFirstSaveOfTheSameDepositIsAConcurrentModification(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::pending());

        $failure = Outcome::of(static fn() => $deposits->save(Cases::pending()));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'dep-a'], $failure->params());
    }

    public function testAClaimMovesAPendingDepositToDepositing(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::pending());

        static::assertTrue($deposits->claim(Cases::depositId('dep-a'), Samples::at(Cases::T1), 3));

        $claimed = $deposits->get(Cases::depositId('dep-a'));
        static::assertSame(DepositState::Depositing, $claimed->state());
        static::assertSame(1, $claimed->attempts());
        static::assertTrue($claimed->claimedAt()->equals(Samples::at(Cases::T1)));
        static::assertTrue($claimed->updatedAt()->equals(Samples::at(Cases::T1)));
        static::assertSame(2, $claimed->version());
    }

    public function testASecondClaimLoses(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::pending());
        $deposits->claim(Cases::depositId('dep-a'), Samples::at(Cases::T1), 3);

        static::assertFalse($deposits->claim(Cases::depositId('dep-a'), Samples::at(Cases::T2), 3));

        $claimed = $deposits->get(Cases::depositId('dep-a'));
        static::assertSame(1, $claimed->attempts());
        static::assertTrue($claimed->claimedAt()->equals(Samples::at(Cases::T1)));
        static::assertSame(2, $claimed->version());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function statesOtherThanPending(): iterable
    {
        foreach (self::ALL_STATES as $state) {
            if ($state === 'pending') {
                continue;
            }
            yield $state => [$state];
        }
    }

    #[DataProvider('statesOtherThanPending')]
    public function testOnlyAPendingDepositCanBeClaimed(string $state): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::inState($state, 'dep-a', 't1', Cases::T0);
        $deposits->save($deposit);

        static::assertFalse($deposits->claim($deposit->id(), Samples::at(Cases::T2), 3));

        $read = $deposits->get($deposit->id());
        static::assertSame($deposit->state(), $read->state());
        static::assertSame(1, $read->version());
        static::assertSame(1, $read->attempts());
    }

    public function testAClaimNeedsAnAttemptLeft(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::notSent();
        $deposits->save($deposit);

        static::assertFalse($deposits->claim($deposit->id(), Samples::at(Cases::T2), 1));
        static::assertFalse($deposits->claim($deposit->id(), Samples::at(Cases::T2), 0));
        static::assertSame(DepositState::Pending, $deposits->get($deposit->id())->state());
        static::assertTrue($deposits->claim($deposit->id(), Samples::at(Cases::T2), 2));
        static::assertSame(2, $deposits->get($deposit->id())->attempts());
    }

    public function testAClaimOfAnUnknownDepositIsNotFound(): void
    {
        $failure = Outcome::of(fn() => $this->createDeposits()->claim(Cases::depositId('dep-missing'), Samples::at(Cases::T1), 3));

        static::assertInstanceOf(RecordNotFound::class, $failure);
        static::assertSame('dep-missing', $failure->identifier());
    }

    public function testAClaimLeavesTheOtherDepositsAlone(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::pending('dep-a'));
        $deposits->save(Cases::pending('dep-b'));

        $deposits->claim(Cases::depositId('dep-a'), Samples::at(Cases::T1), 3);

        static::assertSame(DepositState::Pending, $deposits->get(Cases::depositId('dep-b'))->state());
        static::assertSame(1, $deposits->get(Cases::depositId('dep-b'))->version());
    }

    public function testACopyReadBeforeAClaimCannotOverwriteIt(): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::pending();
        $deposits->save($deposit);
        $stale = clone $deposit;
        $deposits->claim($deposit->id(), Samples::at(Cases::T1), 3);

        $failure = Outcome::of(static fn() => $deposits->save($stale));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame(DepositState::Depositing, $deposits->get($deposit->id())->state());
    }

    #[DataProvider('states')]
    public function testTheOpenDepositsAreFoundByReferenceAndByFingerprintTheClosedOnesAreNot(string $state): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::inState($state, 'dep-a', 't1', Cases::T0);
        $deposits->save($deposit);
        $open = !\in_array($state, ['failed', 'rejected', 'not_received'], true);

        $byReference = $deposits->findOpenByReference($deposit->tenantId(), $deposit->externalReference());
        $byFingerprint = $deposits->findOpenByFingerprint($deposit->tenantId(), $deposit->fingerprint());

        static::assertSame($open, $byReference instanceof Deposit);
        static::assertSame($open, $byFingerprint instanceof Deposit);
        static::assertSame($open ? 'dep-a' : null, $byReference?->id()->value());
        static::assertSame($open ? 'dep-a' : null, $byFingerprint?->id()->value());
    }

    public function testOpenDepositsAreFoundPerTenantAndPerReferenceOrFingerprint(): void
    {
        $deposits = $this->createDeposits();
        $first = Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-shared', Cases::T0);
        $second = Samples::deposit('dep-b', 't2', 'ref-shared', 'variant-shared', Cases::T0);
        $other = Samples::deposit('dep-c', 't1', 'ref-other', 'variant-other', Cases::T0);
        $deposits->save($first);
        $deposits->save($second);
        $deposits->save($other);

        static::assertSame('dep-a', $deposits->findOpenByReference($first->tenantId(), $first->externalReference())?->id()->value());
        static::assertSame('dep-b', $deposits->findOpenByReference($second->tenantId(), $first->externalReference())?->id()->value());
        static::assertSame('dep-c', $deposits->findOpenByReference($first->tenantId(), $other->externalReference())?->id()->value());
        static::assertSame('dep-a', $deposits->findOpenByFingerprint($first->tenantId(), $first->fingerprint())?->id()->value());
        static::assertSame('dep-b', $deposits->findOpenByFingerprint($second->tenantId(), $first->fingerprint())?->id()->value());
        static::assertSame('dep-c', $deposits->findOpenByFingerprint($first->tenantId(), $other->fingerprint())?->id()->value());
        static::assertNotInstanceOf(Deposit::class, $deposits->findOpenByReference(Cases::tenant('t3'), $first->externalReference()));
        static::assertNotInstanceOf(Deposit::class, $deposits->findOpenByFingerprint(Cases::tenant('t3'), $first->fingerprint()));
    }

    public function testAClosedDepositDoesNotHideTheOpenOneOfTheSameReference(): void
    {
        $deposits = $this->createDeposits();
        $closed = Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-shared', Cases::T0);
        $closed->startDeposit(Samples::at(Cases::T1), 3);
        $closed->markFailed('deposit.bad_request', Samples::at(Cases::T2));
        $deposits->save($closed);
        $reopened = Samples::deposit('dep-b', 't1', 'ref-shared', 'variant-shared', Cases::T2);
        $deposits->save($reopened);

        static::assertSame('dep-b', $deposits->findOpenByReference($reopened->tenantId(), $reopened->externalReference())?->id()->value());
        static::assertSame('dep-b', $deposits->findOpenByFingerprint($reopened->tenantId(), $reopened->fingerprint())?->id()->value());
    }

    public function testADepositIsFoundByItsFlowIdWhateverItsStateAndPerTenant(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::received('dep-a', 't1'));
        $deposits->save(Cases::rejected('dep-b', 't1'));
        $deposits->save(Cases::adopted('dep-c', 't2'));
        $deposits->save(Cases::pending('dep-d', 't1'));

        static::assertSame('dep-a', $deposits->findByFlowId(Cases::tenant('t1'), Samples::flowId('flow-dep-a'))?->id()->value());
        static::assertSame('dep-b', $deposits->findByFlowId(Cases::tenant('t1'), Samples::flowId('flow-dep-b'))?->id()->value());
        static::assertSame('dep-c', $deposits->findByFlowId(Cases::tenant('t2'), Samples::flowId('flow-dep-c'))?->id()->value());
        static::assertNotInstanceOf(Deposit::class, $deposits->findByFlowId(Cases::tenant('t1'), Samples::flowId('flow-dep-c')));
        static::assertNotInstanceOf(Deposit::class, $deposits->findByFlowId(Cases::tenant('t1'), Samples::flowId('flow-unknown')));
    }

    #[DataProvider('states')]
    public function testOnlyTheSentDepositsWithoutAFlowIdAreListedForTheReconciliation(string $state): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::inState($state, 'dep-a', 't1', Cases::T1);
        $deposits->save($deposit);

        $listed = array_map(static fn(Deposit $found): string => $found->id()->value(), $deposits->withoutFlowId($deposit->tenantId(), 10));

        static::assertSame(\in_array($state, ['depositing', 'outcome_unknown', 'received', 'rejected'], true) ? ['dep-a'] : [], $listed);
    }

    public function testADepositThatKnowsItsFlowIdIsNotListedForTheReconciliation(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::received('dep-a'));
        $deposits->save(Cases::rejected('dep-b'));
        $deposits->save(Cases::adopted('dep-c'));

        static::assertSame([], $deposits->withoutFlowId(Cases::tenant('t1'), 10));
    }

    public function testTheReconciliationListIsPerTenantOldestClaimFirstThenById(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::inState('depositing', 'dep-d', 't1', '2022-02-01T10:05:00Z'));
        $deposits->save(Cases::inState('depositing', 'dep-b', 't1', '2022-02-01T10:01:00Z'));
        $deposits->save(Cases::inState('outcome_unknown', 'dep-c', 't1', '2022-02-01T10:03:00Z'));
        $deposits->save(Cases::inState('depositing', 'dep-a', 't1', '2022-02-01T10:01:00Z'));
        $deposits->save(Cases::inState('depositing', 'dep-x', 't2', '2022-02-01T09:00:00Z'));

        $ids = static fn(array $found): array => array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $found);

        static::assertSame(['dep-a', 'dep-b', 'dep-c', 'dep-d'], $ids($deposits->withoutFlowId(Cases::tenant('t1'), 10)));
        static::assertSame(['dep-a', 'dep-b'], $ids($deposits->withoutFlowId(Cases::tenant('t1'), 2)));
        static::assertSame(['dep-a'], $ids($deposits->withoutFlowId(Cases::tenant('t1'), 1)));
        static::assertSame(['dep-x'], $ids($deposits->withoutFlowId(Cases::tenant('t2'), 10)));
    }

    #[DataProvider('states')]
    public function testOnlyTheDepositingDepositsCanBeStale(string $state): void
    {
        $deposits = $this->createDeposits();
        $deposit = Cases::inState($state, 'dep-a', 't1', Cases::T0);
        $deposits->save($deposit);

        $stale = $deposits->staleDepositing($deposit->tenantId(), Samples::at('2022-02-01T11:00:00Z'), 10);

        static::assertCount($state === 'depositing' ? 1 : 0, $stale);
    }

    public function testADepositingDepositIsStaleOnlyStrictlyAfterItsClaim(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::depositing('dep-a', 't1', Cases::T1));

        static::assertCount(0, $deposits->staleDepositing(Cases::tenant('t1'), Samples::at('2022-02-01T10:00:59Z'), 10));
        static::assertCount(0, $deposits->staleDepositing(Cases::tenant('t1'), Samples::at(Cases::T1), 10));
        static::assertCount(1, $deposits->staleDepositing(Cases::tenant('t1'), Samples::at('2022-02-01T10:01:01Z'), 10));
    }

    public function testTheStaleListIsPerTenantOldestClaimFirstThenById(): void
    {
        $deposits = $this->createDeposits();
        $deposits->save(Cases::depositing('dep-c', 't1', '2022-02-01T10:03:00Z'));
        $deposits->save(Cases::depositing('dep-b', 't1', '2022-02-01T10:01:00Z'));
        $deposits->save(Cases::depositing('dep-a', 't1', '2022-02-01T10:01:00Z'));
        $deposits->save(Cases::depositing('dep-x', 't2', '2022-02-01T09:00:00Z'));
        $before = Samples::at('2022-02-01T12:00:00Z');

        $ids = static fn(array $found): array => array_map(static fn(Deposit $deposit): string => $deposit->id()->value(), $found);

        static::assertSame(['dep-a', 'dep-b', 'dep-c'], $ids($deposits->staleDepositing(Cases::tenant('t1'), $before, 10)));
        static::assertSame(['dep-a', 'dep-b'], $ids($deposits->staleDepositing(Cases::tenant('t1'), $before, 2)));
        static::assertSame(['dep-a'], $ids($deposits->staleDepositing(Cases::tenant('t1'), $before, 1)));
        static::assertSame(['dep-x'], $ids($deposits->staleDepositing(Cases::tenant('t2'), $before, 10)));
    }
}
