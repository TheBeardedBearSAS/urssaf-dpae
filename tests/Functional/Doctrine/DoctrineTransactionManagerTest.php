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

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineTransactionManager;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;

final class DoctrineTransactionManagerTest extends TestCase
{
    private EntityManagerInterface $entityManager;
    private DoctrineSyncCursorRepository $cursors;
    private DoctrineTransactionManager $transactions;

    protected function setUp(): void
    {
        $this->entityManager = TestDatabase::entityManager();
        $this->cursors = new DoctrineSyncCursorRepository($this->entityManager);
        $this->transactions = new DoctrineTransactionManager($this->entityManager);
    }

    public function testItCommitsAndReturnsTheResultOfTheOperation(): void
    {
        $connection = $this->entityManager->getConnection();

        $result = $this->transactions->transactional(function () use ($connection): string {
            $this->cursors->save(SyncCursor::initial(Cases::tenant('t1')));

            return 'done:' . ($connection->isTransactionActive() ? 'in' : 'out');
        });

        static::assertSame('done:in', $result);
        static::assertFalse($connection->isTransactionActive());
        static::assertInstanceOf(SyncCursor::class, $this->cursors->find(Cases::tenant('t1')));
    }

    public function testItRollsBackAndRethrowsTheSameThrowable(): void
    {
        $failure = new LogicException('boom');

        $thrown = Outcome::of(function () use ($failure): void {
            $this->transactions->transactional(function () use ($failure): never {
                $this->cursors->save(SyncCursor::initial(Cases::tenant('t1')));

                throw $failure;
            });
        });

        static::assertSame($failure, $thrown);
        static::assertFalse($this->entityManager->getConnection()->isTransactionActive());
        static::assertTrue($this->entityManager->isOpen());
        static::assertNotInstanceOf(SyncCursor::class, $this->cursors->find(Cases::tenant('t1')));
    }

    public function testANestedOperationJoinsTheOutermostTransaction(): void
    {
        Outcome::of(function (): void {
            $this->transactions->transactional(function (): never {
                $this->transactions->transactional(function (): void {
                    $this->cursors->save(SyncCursor::initial(Cases::tenant('t1')));
                });

                throw new LogicException('outer failure');
            });
        });

        static::assertNotInstanceOf(SyncCursor::class, $this->cursors->find(Cases::tenant('t1')));
    }

    public function testAFailingNestedOperationIsUndoneToItsSavepointOnly(): void
    {
        $this->transactions->transactional(function (): void {
            $this->cursors->save(SyncCursor::initial(Cases::tenant('t1')));
            Outcome::of(function (): void {
                $this->transactions->transactional(function (): never {
                    $this->cursors->save(SyncCursor::initial(Cases::tenant('t2')));

                    throw new LogicException('inner failure');
                });
            });
        });

        static::assertInstanceOf(SyncCursor::class, $this->cursors->find(Cases::tenant('t1')));
        static::assertNotInstanceOf(SyncCursor::class, $this->cursors->find(Cases::tenant('t2')));
    }

    public function testItDoesNotTouchTheUnitOfWorkOfTheEntityManager(): void
    {
        $this->transactions->transactional(function (): void {
            $this->cursors->save(SyncCursor::initial(Cases::tenant('t1')));
        });

        static::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
    }
}
