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
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class InMemoryReturnRepositoryTest extends TestCase
{
    private static function tracked(string $tenant, string $flow, int $nature, ?string $ops): TrackedReturn
    {
        return TrackedReturn::discover(Samples::tenant($tenant), SampleReturns::listed($flow, $nature, 'OK', Samples::NOW, $ops), null, Samples::at(Samples::NOW));
    }

    public function testAReturnIsFoundUnderItsWholeIdentity(): void
    {
        $repository = new InMemoryReturnRepository();
        $tracked = self::tracked('t1', 'F1', 10, 'OPS01');
        $repository->save($tracked);

        static::assertSame($tracked, $repository->find(Samples::tenant('t1'), $tracked->identity()));
        static::assertNull($repository->find(Samples::tenant('t2'), $tracked->identity()));
        static::assertNull($repository->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F2'), 10, 'OPS01')));
        static::assertNull($repository->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 11, 'OPS01')));
        static::assertNull($repository->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 10, 'OPS02')));
        static::assertNull($repository->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 10, null)));
    }

    public function testAnAbsentOpsIsNotAnEmptyOne(): void
    {
        $repository = new InMemoryReturnRepository();
        $absent = self::tracked('t1', 'F1', 10, null);
        $empty = self::tracked('t1', 'F1', 10, '');
        $repository->save($absent);
        $repository->save($empty);

        static::assertSame($absent, $repository->find(Samples::tenant('t1'), $absent->identity()));
        static::assertSame($empty, $repository->find(Samples::tenant('t1'), $empty->identity()));
    }

    public function testComponentsCannotBeShiftedAcrossTheKey(): void
    {
        $repository = new InMemoryReturnRepository();
        $repository->save(self::tracked('t1', 'F1|10', 11, null));

        static::assertNull($repository->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 10, '11')));
        static::assertNull($repository->find(Samples::tenant('t1|F1'), new ReturnIdentity(FlowId::fromString('10'), 11, null)));
    }

    public function testSavingAgainReplacesTheStoredReturn(): void
    {
        $repository = new InMemoryReturnRepository();
        $first = self::tracked('t1', 'F1', 10, null);
        $second = self::tracked('t1', 'F1', 10, null);
        $repository->save($first);
        $repository->save($second);

        static::assertSame($second, $repository->find(Samples::tenant('t1'), $second->identity()));
    }

    public function testContentsFollowTheSameIdentityRules(): void
    {
        $store = new InMemoryReturnContentStore();
        $identity = new ReturnIdentity(FlowId::fromString('F1'), 10, null);
        $content = ReturnContent::of('<doc/>', 'application/xml');
        $store->save(Samples::tenant('t1'), $identity, $content);

        static::assertSame($content, $store->find(Samples::tenant('t1'), $identity));
        static::assertNull($store->find(Samples::tenant('t2'), $identity));
        static::assertNull($store->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F2'), 10, null)));
        static::assertNull($store->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 11, null)));
        static::assertNull($store->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 10, '')));
        static::assertNull($store->find(Samples::tenant('t1'), new ReturnIdentity(FlowId::fromString('F1'), 10, 'OPS01')));
    }

    public function testACursorIsKeptPerTenant(): void
    {
        $repository = new InMemorySyncCursorRepository();
        $cursor = SyncCursor::initial(Samples::tenant('t1'))->refused();

        static::assertNull($repository->find(Samples::tenant('t1')));

        $repository->save($cursor);

        static::assertSame($cursor, $repository->find(Samples::tenant('t1')));
        static::assertNull($repository->find(Samples::tenant('t2')));
    }
}
