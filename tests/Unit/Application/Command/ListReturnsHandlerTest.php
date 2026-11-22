<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Command;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturns;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ListReturnsHandlerTest extends TestCase
{
    public function testItListsAndStoresTheReturnsWithoutMovingTheCursor(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $first = SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null);
        $second = SampleReturns::listedWithoutDocument('F2', 10, 'EN_COURS', '2022-02-01T11:30:00Z', null);
        $harness->gateway->willList([$first, $second]);
        $from = Samples::at('2022-02-01T10:00:00Z');
        $to = Samples::at('2022-02-01T12:00:00Z');

        $listed = $harness->listHandler()(new ListReturns(Samples::tenant('tenant-1'), $from, $to));

        static::assertSame([$first, $second], $listed);
        static::assertEquals([[Samples::tenant('tenant-1'), $from, $to]], $harness->gateway->listCalls());
        static::assertInstanceOf(TrackedReturn::class, $harness->returns->find(Samples::tenant('tenant-1'), $first->identity()));
        static::assertInstanceOf(TrackedReturn::class, $harness->returns->find(Samples::tenant('tenant-1'), $second->identity()));
        static::assertNull($harness->cursors->find(Samples::tenant('tenant-1')));
        static::assertCount(2, $harness->outbox->events());
        static::assertInstanceOf(ReturnAvailable::class, $harness->outbox->events()[0]);
        static::assertSame('F1', $harness->outbox->events()[0]->aggregateId);
        static::assertSame('F2', $harness->outbox->events()[1]->aggregateId);
    }

    public function testTheOneDatePathIsUsedWithoutAnEnd(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $harness->gateway->willList([]);

        $listed = $harness->listHandler()(new ListReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), null));

        static::assertSame([], $listed);
        static::assertNull($harness->gateway->listCalls()[0][2]);
    }

    public function testAReturnListedTwiceIsStoredOnce(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null);
        $harness->gateway->willList([$reference]);
        $harness->gateway->willList([$reference]);
        $command = new ListReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), null);

        $harness->listHandler()($command);
        $harness->listHandler()($command);

        static::assertCount(1, $harness->outbox->events());
    }

    public function testAGatewayRefusalPropagatesAndStoresNothing(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $harness->gateway->willList(PollingConstraintViolated::withStatus(429, 30));

        $this->expectException(PollingConstraintViolated::class);

        try {
            $harness->listHandler()(new ListReturns(Samples::tenant('tenant-1'), Samples::at('2022-02-01T10:00:00Z'), null));
        } finally {
            static::assertSame([], $harness->journal->entries());
        }
    }
}
