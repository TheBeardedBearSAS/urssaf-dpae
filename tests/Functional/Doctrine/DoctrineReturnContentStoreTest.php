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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract\ReturnContentStoreContract;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DoctrineReturnContentStoreTest extends TestCase
{
    use ReturnContentStoreContract;

    protected function createContents(): ReturnContentStore
    {
        return new DoctrineReturnContentStore(TestDatabase::entityManager(), new FixedClock(Cases::T2));
    }

    public function testTheRowKeepsTheDocumentItsSizeItsDigestAndTheInstantItWasStoredAt(): void
    {
        $entityManager = TestDatabase::entityManager();
        $store = new DoctrineReturnContentStore($entityManager, new FixedClock('2022-02-01T12:00:00+02:00'));
        $identity = Cases::identity('flow-a', 10, null);
        $store->save(Cases::tenant('t1'), $identity, ReturnContent::of('<doc>é</doc>', 'application/xml'));

        $row = $entityManager->getConnection()->fetchAssociative('SELECT * FROM urssaf_dpae_return_contents');

        static::assertSame(
            self::sorted([
                'return_id' => ReturnRecord::key(Cases::tenant('t1'), $identity),
                'tenant_id' => 't1',
                'content_type' => 'application/xml',
                'content' => base64_encode('<doc>é</doc>'),
                'size' => 13,
                'sha256' => hash('sha256', '<doc>é</doc>'),
                'downloaded_at' => '2022-02-01 10:00:00',
            ]),
            self::sorted($row),
        );
    }

    public function testTheContentsStoredBeforeTheInstantAreForgottenForTheTenantOnly(): void
    {
        $entityManager = TestDatabase::entityManager();
        $clock = new FixedClock('2022-02-01T10:00:00Z');
        $store = new DoctrineReturnContentStore($entityManager, $clock);
        $store->save(Cases::tenant('t1'), Cases::identity('flow-a', 10, null), ReturnContent::of('<a/>', 'application/xml'));
        $store->save(Cases::tenant('t2'), Cases::identity('flow-a', 10, null), ReturnContent::of('<b/>', 'application/xml'));
        $clock->moveTo('2022-02-02T10:00:00Z');
        $store->save(Cases::tenant('t1'), Cases::identity('flow-b', 10, null), ReturnContent::of('<c/>', 'application/xml'));

        static::assertSame(0, $store->deleteDownloadedBefore(Cases::tenant('t1'), Samples::at('2022-02-01T10:00:00Z')));
        static::assertSame(1, $store->deleteDownloadedBefore(Cases::tenant('t1'), Samples::at('2022-02-02T10:00:00Z')));

        static::assertNotInstanceOf(ReturnContent::class, $store->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null)));
        static::assertInstanceOf(ReturnContent::class, $store->find(Cases::tenant('t1'), Cases::identity('flow-b', 10, null)));
        static::assertInstanceOf(ReturnContent::class, $store->find(Cases::tenant('t2'), Cases::identity('flow-a', 10, null)));
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
