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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract\ReturnRepositoryContract;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DoctrineReturnRepositoryTest extends TestCase
{
    use ReturnRepositoryContract;

    protected function createReturns(): ReturnRepository
    {
        return new DoctrineReturnRepository(TestDatabase::entityManager());
    }

    public function testTheRowKeepsTheRawValuesAndTheDerivedKey(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $return = Cases::discoveredFor('t1', Cases::listed('flow-a', 11, 'ops-1'), 'dep-a');
        $repository->save($return);

        $row = $entityManager->getConnection()->fetchAssociative('SELECT * FROM urssaf_dpae_returns');

        static::assertSame(
            self::sorted([
                'id' => ReturnRecord::key($return->tenantId(), $return->identity()),
                'tenant_id' => 't1',
                'flow_id' => 'flow-a',
                'nature' => 11,
                'has_ops' => 1,
                'ops' => 'ops-1',
                'status' => 'OK',
                'publication_raw' => '20220201100000',
                'publication_at' => '2022-02-01 10:01:00',
                'production_raw' => '20220201090000',
                'production_at' => '2022-02-01 10:00:00',
                'return_id' => '7',
                'delegate' => 'delegate-1',
                'url' => 'https://telechargement.example.test/retour/7',
                'deposit_id' => 'dep-a',
                'download_state' => 'pending',
                'first_seen_at' => '2022-02-01 10:01:00',
                'updated_at' => '2022-02-01 10:01:00',
            ]),
            self::sorted($row),
        );
    }

    public function testAnAbsentOpsAndAnEmptyOneHaveDifferentKeysAndRows(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $repository->save(Cases::discovered('t1', Cases::listed('flow-a', 10, null)));
        $repository->save(Cases::discovered('t1', Cases::listed('flow-a', 10, '')));

        static::assertSame(
            [[0, ''], [1, '']],
            array_map(static fn(array $row): array => [$row['has_ops'], $row['ops']], $entityManager->getConnection()->fetchAllAssociative('SELECT has_ops, ops FROM urssaf_dpae_returns ORDER BY has_ops')),
        );
        static::assertNotSame(
            ReturnRecord::key(Cases::tenant('t1'), Cases::identity('flow-a', 10, null)),
            ReturnRecord::key(Cases::tenant('t1'), Cases::identity('flow-a', 10, '')),
        );
    }

    public function testTheReturnsFirstSeenBeforeTheInstantAreForgottenWithTheirContents(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $contents = new DoctrineReturnContentStore($entityManager, new FixedClock(Cases::T2));
        foreach ([['t1', 'flow-a'], ['t1', 'flow-b'], ['t2', 'flow-a']] as [$tenant, $flow]) {
            $repository->save(Cases::discovered($tenant, Cases::listed($flow, 10, null)));
            $contents->save(Cases::tenant($tenant), Cases::identity($flow, 10, null), ReturnContent::of('<c/>', 'application/xml'));
        }

        static::assertSame(0, $repository->deleteSeenBefore(Cases::tenant('t1'), Samples::at(Cases::T1)));
        static::assertSame(2, $repository->deleteSeenBefore(Cases::tenant('t1'), Samples::at('2022-02-01T10:01:01Z')));

        static::assertNotInstanceOf(TrackedReturn::class, $repository->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null)));
        static::assertNotInstanceOf(ReturnContent::class, $contents->find(Cases::tenant('t1'), Cases::identity('flow-b', 10, null)));
        static::assertInstanceOf(TrackedReturn::class, $repository->find(Cases::tenant('t2'), Cases::identity('flow-a', 10, null)));
        static::assertInstanceOf(ReturnContent::class, $contents->find(Cases::tenant('t2'), Cases::identity('flow-a', 10, null)));
    }

    public function testASynchronisationThatListedTheReturnBeforeItsDownloadNeverBringsItBackToPending(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $reference = Cases::listed('flow-a', 10, null);
        $repository->save(Cases::discovered('t1', $reference));
        $listing = $repository->find(Cases::tenant('t1'), $reference->identity());
        $download = $repository->find(Cases::tenant('t1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $listing);
        static::assertInstanceOf(TrackedReturn::class, $download);
        $download->markDownloaded(Samples::at(Cases::T2));
        $repository->save($download);

        $listing->refresh(Cases::listed('flow-a', 10, null, 'KO'), Samples::at('2022-02-01T10:03:00Z'));
        $repository->save($listing);

        static::assertSame(
            [['downloaded', 'OK']],
            $entityManager->getConnection()->fetchAllNumeric('SELECT download_state, status FROM urssaf_dpae_returns'),
        );
        static::assertSame(DownloadState::Downloaded, $repository->find(Cases::tenant('t1'), $reference->identity())?->downloadState());
    }

    public function testAPendingReturnIsUpdatedByAListingThatSawIt(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $reference = Cases::listed('flow-a', 10, null);
        $repository->save(Cases::discovered('t1', $reference));
        $tracked = $repository->find(Cases::tenant('t1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $tracked);

        $tracked->refresh(Cases::listed('flow-a', 10, null, 'KO'), Samples::at('2022-02-01T10:03:00Z'));
        $repository->save($tracked);

        static::assertSame(
            [['pending', 'KO', '2022-02-01 10:03:00']],
            $entityManager->getConnection()->fetchAllNumeric('SELECT download_state, status, updated_at FROM urssaf_dpae_returns'),
        );
    }

    public function testADownloadedReturnIsStillRefreshedByAListingThatSawIt(): void
    {
        $entityManager = TestDatabase::entityManager();
        $repository = new DoctrineReturnRepository($entityManager);
        $reference = Cases::listed('flow-a', 10, null);
        $repository->save(Cases::discovered('t1', $reference));
        $tracked = $repository->find(Cases::tenant('t1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $tracked);
        $tracked->markDownloaded(Samples::at(Cases::T2));
        $repository->save($tracked);
        $again = $repository->find(Cases::tenant('t1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $again);

        $again->refresh(Cases::listed('flow-a', 10, null, 'KO'), Samples::at('2022-02-01T10:03:00Z'));
        $repository->save($again);

        static::assertSame(
            [['downloaded', 'KO']],
            $entityManager->getConnection()->fetchAllNumeric('SELECT download_state, status FROM urssaf_dpae_returns'),
        );
    }

    /**
     * @param array<string, mixed>|false $row
     *
     * @return array<string, mixed>
     */
    private static function sorted(array|false $row): array
    {
        static::assertIsArray($row);
        ksort($row);

        return $row;
    }
}
