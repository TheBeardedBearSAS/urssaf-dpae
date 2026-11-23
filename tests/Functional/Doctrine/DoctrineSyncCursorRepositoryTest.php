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
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineSyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract\SyncCursorRepositoryContract;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DoctrineSyncCursorRepositoryTest extends TestCase
{
    use SyncCursorRepositoryContract;

    protected function createCursors(): SyncCursorRepository
    {
        return new DoctrineSyncCursorRepository(TestDatabase::entityManager());
    }

    public function testTheRowHoldsTheCursorOfTheTenant(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineSyncCursorRepository($entityManager);
        $repository->save(SyncCursor::initial(Cases::tenant('t1'))->advancedTo(Samples::at(Cases::T1), Samples::at(Cases::T2))->refused());

        static::assertEquals(
            ['tenant_id' => 't1', 'cursor_at' => '2022-02-01 10:01:00', 'last_success_at' => '2022-02-01 10:02:00', 'consecutive_refusals' => 1],
            $entityManager->getConnection()->fetchAssociative('SELECT * FROM urssaf_dpae_sync_cursors'),
        );
    }
}
