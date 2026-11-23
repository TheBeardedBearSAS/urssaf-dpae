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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * Behaviour shared by every {@see ReturnRepository} adapter (ADR 0006, 0008).
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 */
trait ReturnRepositoryContract
{
    abstract protected function createReturns(): ReturnRepository;

    /**
     * @return array<string, array{TrackedReturn}>
     */
    public static function storedReturns(): array
    {
        $downloaded = Cases::discovered('t1', Cases::listed('flow-a', 10, null));
        $downloaded->markDownloaded(Samples::at(Cases::T2));
        $downloaded->releaseEvents();
        $notDownloadable = Cases::discovered('t1', Cases::listed('flow-a', 10, null));
        $notDownloadable->markNotDownloadable(Samples::at(Cases::T2));
        $purged = Cases::discovered('t1', Cases::listed('flow-a', 10, null));
        $purged->markPurged(Samples::at(Cases::T2));

        return [
            'pending' => [Cases::discovered('t1', Cases::listed('flow-a', 10, null))],
            'pending with a deposit and an ops' => [Cases::discoveredFor('t1', Cases::listed('flow-a', 11, 'ops-1'), 'dep-a')],
            'downloaded' => [$downloaded],
            'not downloadable' => [$notDownloadable],
            'purged' => [$purged],
            'without any instant nor document' => [Cases::discovered('t1', ReturnReference::fromListing(
                Samples::flowId('flow-a'),
                41,
                'EN_COURS',
                'unparsable',
                '',
                null,
                null,
                null,
                '',
                null,
                null,
            ))],
            'with a legacy id only' => [Cases::discovered('t1', ReturnReference::fromListing(
                Samples::flowId('flow-a'),
                50,
                'ANO',
                '20220201100000',
                '20220201090000',
                Samples::at(Cases::T1),
                Samples::at(Cases::T0),
                '7',
                null,
                null,
                null,
            ))],
        ];
    }

    #[DataProvider('storedReturns')]
    public function testASavedReturnIsReadBackIdentically(TrackedReturn $return): void
    {
        $returns = $this->createReturns();
        $returns->save($return);

        $read = $returns->find($return->tenantId(), $return->identity());

        static::assertInstanceOf(TrackedReturn::class, $read);
        static::assertEquals($return, $read);
        static::assertSame($return->downloadState(), $read->downloadState());
        static::assertSame($return->reference()->ops(), $read->reference()->ops());
        static::assertSame([], $read->releaseEvents());
    }

    public function testAnUnknownReturnIsNotFound(): void
    {
        static::assertNotInstanceOf(TrackedReturn::class, $this->createReturns()->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null)));
    }

    public function testASavedReturnIsReplacedUnderItsIdentity(): void
    {
        $returns = $this->createReturns();
        $first = Cases::discovered('t1', Cases::listedWithoutDocument('flow-a', 10, null));
        $returns->save($first);
        $stored = $returns->find($first->tenantId(), $first->identity());
        static::assertInstanceOf(TrackedReturn::class, $stored);
        $stored->refresh(Cases::listed('flow-a', 10, null, 'KO'), Samples::at(Cases::T2));
        $stored->markDownloaded(Samples::at(Cases::T2));
        $stored->linkTo(DepositId::fromString('dep-a'));
        $stored->releaseEvents();
        $returns->save($stored);

        $read = $returns->find($first->tenantId(), $first->identity());

        static::assertInstanceOf(TrackedReturn::class, $read);
        static::assertEquals($stored, $read);
        static::assertSame('KO', $read->reference()->statusCode());
        static::assertSame(DownloadState::Downloaded, $read->downloadState());
        static::assertSame('dep-a', $read->depositId()?->value());
        static::assertTrue($read->updatedAt()->equals(Samples::at(Cases::T2)));
        static::assertTrue($read->firstSeenAt()->equals(Samples::at(Cases::T1)));
    }

    public function testAReturnIsKeptPerTenantFluxNatureAndOps(): void
    {
        $returns = $this->createReturns();
        $variants = [
            ['t1', 'flow-a', 10, null],
            ['t1', 'flow-a', 10, ''],
            ['t1', 'flow-a', 10, 'ops-1'],
            ['t1', 'flow-a', 11, null],
            ['t1', 'flow-b', 10, null],
            ['t2', 'flow-a', 10, null],
        ];
        foreach ($variants as $index => [$tenant, $flow, $nature, $ops]) {
            $returns->save(Cases::discovered($tenant, Cases::listed($flow, $nature, $ops, 'S' . $index)));
        }

        foreach ($variants as $index => [$tenant, $flow, $nature, $ops]) {
            $read = $returns->find(Cases::tenant($tenant), Cases::identity($flow, $nature, $ops));
            static::assertInstanceOf(TrackedReturn::class, $read);
            static::assertSame('S' . $index, $read->reference()->statusCode());
            static::assertSame($ops, $read->reference()->ops());
        }
        static::assertNotInstanceOf(TrackedReturn::class, $returns->find(Cases::tenant('t3'), Cases::identity('flow-a', 10, null)));
        static::assertNotInstanceOf(TrackedReturn::class, $returns->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, 'ops-2')));
    }
}
