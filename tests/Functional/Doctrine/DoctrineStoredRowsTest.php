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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use ValueError;

/**
 * Rows that were altered by hand are refused, never coerced.
 */
final class DoctrineStoredRowsTest extends TestCase
{
    public function testADepositWithAnUnknownStateIsRefused(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $repository->save(Cases::pending());
        $entityManager->getConnection()->executeStatement("UPDATE urssaf_dpae_deposits SET state = 'bogus'");

        static::assertInstanceOf(ValueError::class, Outcome::of(static fn(): Deposit => $repository->get(Cases::depositId('dep-a'))));
    }

    public function testADepositWithAnUnknownModeIsRefused(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineDepositRepository($entityManager);
        $repository->save(Cases::pending());
        $entityManager->getConnection()->executeStatement("UPDATE urssaf_dpae_deposits SET mode = 'bogus'");

        static::assertInstanceOf(ValueError::class, Outcome::of(static fn(): Deposit => $repository->get(Cases::depositId('dep-a'))));
    }

    public function testAReturnWithAnUnknownDownloadStateIsRefused(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $repository->save(Cases::discovered('t1', Cases::listed('flow-a', 10, null)));
        $entityManager->getConnection()->executeStatement("UPDATE urssaf_dpae_returns SET download_state = 'bogus'");

        static::assertInstanceOf(ValueError::class, Outcome::of(static fn(): ?TrackedReturn => $repository->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null))));
    }
}
