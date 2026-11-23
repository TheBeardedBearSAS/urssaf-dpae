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

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositFlowStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract\DepositRepositoryContract;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\FileDatabase;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Json;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use Throwable;

/**
 * @mago-expect lint:too-many-methods One method per scenario of the Doctrine adapter, on top of the shared contract
 */
final class DoctrineDepositRepositoryTest extends TestCase
{
    use DepositRepositoryContract;

    private ?FileDatabase $file = null;

    protected function tearDown(): void
    {
        $this->file?->delete();
    }

    protected function createDeposits(): DepositRepository
    {
        return new DoctrineDepositRepository(TestDatabase::entityManager());
    }

    public function testTheRowRepeatsTheReferenceAndTheFingerprintWhileTheDepositIsOpen(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $deposit = Cases::pending();
        $repository->save($deposit);

        $row = self::row($entityManager);

        static::assertSame('dep-a', $row['id']);
        static::assertSame('t1', $row['tenant_id']);
        static::assertSame('ref-dep-a', $row['external_reference']);
        static::assertSame($deposit->fingerprint()->value(), $row['fingerprint']);
        static::assertSame('test', $row['mode']);
        static::assertSame(Samples::SIRET, $row['declarant_siret']);
        static::assertSame('pending', $row['state']);
        static::assertSame(0, $row['attempts']);
        static::assertSame('2022-02-01 10:00:00', $row['claimed_at']);
        static::assertSame('2022-02-01 10:00:00', $row['updated_at']);
        static::assertNull($row['flow_id']);
        static::assertNull($row['result']);
        static::assertNull($row['last_error_code']);
        static::assertSame(0, $row['adopted']);
        static::assertSame(1, $row['version']);
        static::assertSame('ref-dep-a', $row['open_reference']);
        static::assertSame($deposit->fingerprint()->value(), $row['open_fingerprint']);
    }

    public function testTheOpenColumnsAreNullOnceTheDepositIsClosed(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $repository->save(Cases::failed());

        $row = self::row($entityManager);

        static::assertSame('failed', $row['state']);
        static::assertSame('deposit.bad_request', $row['last_error_code']);
        static::assertNull($row['open_reference']);
        static::assertNull($row['open_fingerprint']);
        static::assertSame('ref-dep-a', $row['external_reference']);
    }

    public function testTheResultAndTheFlowIdAreStoredWithTheDeposit(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $repository->save(Cases::adopted());

        $row = self::row($entityManager);

        static::assertSame('flow-dep-a', $row['flow_id']);
        static::assertSame(1, $row['adopted']);
        static::assertNull($row['result']);
        static::assertSame('received', $row['state']);
    }

    public function testASavedResultIsAJsonDocument(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $repository->save(Cases::rejected());

        $result = Json::decode(self::resultOf($entityManager));

        static::assertSame('rejection', $result['kind']);
        static::assertSame(['01', 'motif é'], $result['reasons']);
        static::assertSame(base64_encode(Cases::document()->bytes()), $result['document']);
    }

    public function testTwoOpenDepositsCannotShareAReference(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $repository->save(Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-a', Cases::T0));

        $failure = Outcome::of(static fn() => $repository->save(Samples::deposit('dep-b', 't1', 'ref-shared', 'variant-b', Cases::T0)));

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame('deposit.reference_conflict', $failure->violationCode());
    }

    public function testTheSameDepositSavedTwiceUnderTheSameReferenceAndFlowIsAConcurrentModification(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $repository->save(Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-shared', Cases::T0));

        $failure = Outcome::of(static fn() => $repository->save(Samples::deposit('dep-b', 't1', 'ref-shared', 'variant-shared', Cases::T0)));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'dep-b'], $failure->params());
    }

    public function testTwoOpenDepositsCannotShareAFlow(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $repository->save(Samples::deposit('dep-a', 't1', 'ref-a', 'variant-shared', Cases::T0));

        $failure = Outcome::of(static fn() => $repository->save(Samples::deposit('dep-b', 't1', 'ref-b', 'variant-shared', Cases::T0)));

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame('deposit.flow_already_open', $failure->violationCode());
    }

    public function testTwoTenantsMayShareAReferenceAndAFlow(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $repository->save(Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-shared', Cases::T0));
        $repository->save(Samples::deposit('dep-b', 't2', 'ref-shared', 'variant-shared', Cases::T0));

        static::assertSame('dep-b', $repository->get(Cases::depositId('dep-b'))->id()->value());
    }

    public function testTwoDepositsCannotHoldTheSameFlowIdAndTheSecondOneReloadsAndDecidesAgain(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $first = self::depositing('dep-a');
        $second = self::depositing('dep-b');
        $repository->save($first);
        $repository->save($second);
        $first->recordResult(SampleAnswers::receipt('flow-shared'), Samples::at(Cases::T2));
        $repository->save($first);
        $second->recordResult(SampleAnswers::receipt('flow-shared'), Samples::at(Cases::T2));

        $failure = Outcome::of(static fn() => $repository->save($second));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'dep-b'], $failure->params());
        static::assertSame(DepositState::Depositing, $repository->get($second->id())->state());
    }

    public function testAnInsertedDepositWithTheFlowIdOfAnotherIsAConcurrentModification(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $first = self::depositing('dep-a');
        $first->recordResult(SampleAnswers::receipt('flow-shared'), Samples::at(Cases::T2));
        $repository->save($first);
        $second = self::depositing('dep-b');
        $second->recordResult(SampleAnswers::receipt('flow-shared'), Samples::at(Cases::T2));

        $failure = Outcome::of(static fn() => $repository->save($second));

        static::assertInstanceOf(ConcurrentModification::class, $failure);
        static::assertSame(['aggregate' => 'deposit', 'identifier' => 'dep-b'], $failure->params());
    }

    public function testAUniqueViolationThatNoOtherDepositExplainsIsNotHidden(): void
    {
        $entityManager = TestDatabase::entityManager();
        $entityManager->getConnection()->executeStatement(
            "CREATE TRIGGER refuse BEFORE INSERT ON urssaf_dpae_deposits BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: urssaf_dpae_deposits.id'); END",
        );
        $repository = new DoctrineDepositRepository($entityManager);

        $this->expectException(UniqueConstraintViolationException::class);
        $repository->save(Cases::failed());
    }

    public function testALostRaceDoesNotCloseTheEntityManager(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $deposit = Cases::pending();
        $repository->save($deposit);
        $stale = clone $deposit;
        $repository->save($deposit);

        Outcome::of(static fn() => $repository->save($stale));

        static::assertTrue($entityManager->isOpen());
        static::assertFalse($entityManager->getConnection()->isTransactionActive());
    }

    public function testTwoEntityManagersOnTheSameDatabaseShareTheClaimAndTheVersion(): void
    {
        $this->file = new FileDatabase();
        $one = new DoctrineDepositRepository($this->file->entityManager());
        $two = new DoctrineDepositRepository($this->file->entityManager());
        $one->save(Cases::pending());
        $seenByTwo = $two->get(Cases::depositId('dep-a'));

        static::assertTrue($one->claim(Cases::depositId('dep-a'), Samples::at(Cases::T1), 3));
        static::assertFalse($two->claim(Cases::depositId('dep-a'), Samples::at(Cases::T2), 3));

        $failure = Outcome::of(static fn() => $two->save($seenByTwo));
        static::assertInstanceOf(ConcurrentModification::class, $failure);
        $reloaded = $two->get(Cases::depositId('dep-a'));
        static::assertSame(DepositState::Depositing, $reloaded->state());
        $reloaded->markOutcomeUnknown('deposit.outcome_unknown', Samples::at(Cases::T2));
        $two->save($reloaded);
        static::assertSame(DepositState::OutcomeUnknown, $one->get(Cases::depositId('dep-a'))->state());
    }

    public function testASaveJoinsTheTransactionOfTheTransactionManager(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $transactions = new DoctrineTransactionManager($entityManager);

        Outcome::of(static fn(): mixed => $transactions->transactional(static function () use ($repository): never {
            $repository->save(Cases::pending());

            throw new LogicException('Rolled back.');
        }));

        static::assertFalse($entityManager->getConnection()->fetchOne('SELECT id FROM urssaf_dpae_deposits'));
    }

    public function testARefusedSaveLeavesTheSurroundingTransactionUsable(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $transactions = new DoctrineTransactionManager($entityManager);
        $repository->save(Samples::deposit('dep-a', 't1', 'ref-shared', 'variant-a', Cases::T0));

        $failure = $transactions->transactional(static function () use ($repository): ?Throwable {
            $refused = Outcome::of(static fn() => $repository->save(Samples::deposit('dep-b', 't1', 'ref-shared', 'variant-b', Cases::T0)));
            $repository->save(Samples::deposit('dep-c', 't1', 'ref-c', 'variant-c', Cases::T0));

            return $refused;
        });

        static::assertInstanceOf(InvariantViolation::class, $failure);
        static::assertSame('dep-c', $repository->get(Cases::depositId('dep-c'))->id()->value());
        static::assertFalse($entityManager->getConnection()->isTransactionActive());
    }

    public function testTheFinishedDepositsOfTheTenantAreForgottenWithTheirFlow(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $flows = new DoctrineDepositFlowStore($entityManager);
        foreach (['received', 'rejected', 'failed', 'not_received'] as $index => $state) {
            $deposit = Cases::inState($state, 'dep-' . $state, 't1', Cases::T0);
            $repository->save($deposit);
            $flows->keep($deposit->id(), '<flow>' . $index . '</flow>');
        }
        foreach (['pending', 'depositing', 'outcome_unknown', 'invalid_credentials'] as $state) {
            $deposit = Cases::inState($state, 'dep-' . $state, 't1', Cases::T0);
            $repository->save($deposit);
            $flows->keep($deposit->id(), '<flow>' . $state . '</flow>');
        }
        $repository->save(Cases::inState('failed', 'dep-other-tenant', 't2', Cases::T0));
        $flows->keep(Cases::depositId('dep-other-tenant'), '<flow>other</flow>');
        $recent = Samples::deposit('dep-recent', 't1', 'ref-recent', 'variant-recent', '2022-03-01T10:00:00Z');
        $recent->startDeposit(Samples::at('2022-03-01T10:00:00Z'), 3);
        $recent->markFailed('deposit.bad_request', Samples::at('2022-03-01T10:01:00Z'));
        $repository->save($recent);
        $flows->keep($recent->id(), '<flow>recent</flow>');

        $forgotten = $repository->deleteFinishedBefore(Cases::tenant('t1'), Samples::at('2022-02-02T00:00:00Z'));

        static::assertSame(4, $forgotten);
        $remaining = $entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_dpae_deposits ORDER BY id');
        static::assertSame(['dep-depositing', 'dep-invalid_credentials', 'dep-other-tenant', 'dep-outcome_unknown', 'dep-pending', 'dep-recent'], $remaining);
        $keptFlows = $entityManager->getConnection()->fetchFirstColumn('SELECT deposit_id FROM urssaf_dpae_deposit_flows ORDER BY deposit_id');
        static::assertSame($remaining, $keptFlows);
    }

    public function testADepositLastUpdatedAtTheInstantIsNotForgotten(): void
    {
        $repository = new DoctrineDepositRepository(TestDatabase::entityManager());
        $repository->save(Cases::failed());

        static::assertSame(0, $repository->deleteFinishedBefore(Cases::tenant('t1'), Samples::at(Cases::T2)));
        static::assertSame(1, $repository->deleteFinishedBefore(Cases::tenant('t1'), Samples::at('2022-02-01T10:02:01Z')));
    }

    public function testADepositIsErasedWithItsFlowByIdAndOthersStay(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $flows = new DoctrineDepositFlowStore($entityManager);
        foreach (['dep-a', 'dep-b'] as $id) {
            $repository->save(self::depositing($id));
            $flows->keep(Cases::depositId($id), '<flow/>');
        }

        static::assertTrue($repository->delete(Cases::depositId('dep-a')));
        static::assertFalse($repository->delete(Cases::depositId('dep-a')));

        static::assertSame(['dep-b'], $entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_dpae_deposits'));
        static::assertSame(['dep-b'], $entityManager->getConnection()->fetchFirstColumn('SELECT deposit_id FROM urssaf_dpae_deposit_flows'));
    }

    public function testTheDepositsOfAReferenceAreErasedWhateverTheirStateAndOnlyForTheTenant(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $flows = new DoctrineDepositFlowStore($entityManager);
        $failed = Cases::failed('dep-old');
        $repository->save($failed);
        $repository->save(Samples::deposit('dep-new', 't1', 'ref-dep-old', 'variant-other', Cases::T0));
        $repository->save(Samples::deposit('dep-keep', 't1', 'ref-keep', 'variant-keep', Cases::T0));
        $repository->save(Samples::deposit('dep-t2', 't2', 'ref-dep-old', 'variant-t2', Cases::T0));
        foreach (['dep-old', 'dep-new', 'dep-keep', 'dep-t2'] as $id) {
            $flows->keep(Cases::depositId($id), '<flow/>');
        }

        $erased = $repository->deleteByReference(Cases::tenant('t1'), Cases::reference('dep-old'));

        static::assertSame(2, $erased);
        static::assertSame(['dep-keep', 'dep-t2'], $entityManager->getConnection()->fetchFirstColumn('SELECT id FROM urssaf_dpae_deposits ORDER BY id'));
        static::assertSame(['dep-keep', 'dep-t2'], $entityManager->getConnection()->fetchFirstColumn('SELECT deposit_id FROM urssaf_dpae_deposit_flows ORDER BY deposit_id'));
        static::assertSame(0, $repository->deleteByReference(Cases::tenant('t1'), Cases::reference('dep-old')));
    }

    private static function depositing(string $id): Deposit
    {
        $deposit = Samples::deposit($id, 't1', 'ref-' . $id, 'variant-' . $id, Cases::T0);
        $deposit->startDeposit(Samples::at(Cases::T1), 3);

        return $deposit;
    }

    private static function resultOf(EntityManagerInterface $entityManager): string
    {
        // @mago-expect analysis:mixed-assignment The column is untyped: the assertion below narrows it.
        $result = self::row($entityManager)['result'];
        static::assertIsString($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(EntityManagerInterface $entityManager): array
    {
        $row = $entityManager->getConnection()->fetchAssociative('SELECT * FROM urssaf_dpae_deposits');
        static::assertIsArray($row);

        return $row;
    }
}
