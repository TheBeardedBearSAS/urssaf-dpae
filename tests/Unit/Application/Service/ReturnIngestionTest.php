<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleAnswers;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ReturnIngestionTest extends TestCase
{
    private const T = '2022-02-01T11:00:00Z';

    private static function stored(Harness $harness, ReturnReference $reference): TrackedReturn
    {
        $tracked = $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $tracked);

        return $tracked;
    }

    public function testNewReturnsAreCountedStoredAndAnnounced(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $first = SampleReturns::listed('F1', 10, 'OK', self::T, null);
        $second = SampleReturns::listedWithoutDocument('F2', 10, 'EN_COURS', self::T, null);
        $events = new RecordedEvents();

        $discovered = $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$first, $second], Samples::at(Samples::NOW), $events);

        static::assertSame(2, $discovered);
        static::assertSame($first, self::stored($harness, $first)->reference());
        static::assertSame($second, self::stored($harness, $second)->reference());
        static::assertSame(Samples::NOW, self::stored($harness, $first)->firstSeenAt()->toIso8601());
        static::assertCount(2, $events->all());
        static::assertInstanceOf(ReturnAvailable::class, $events->all()[0]);
    }

    public function testAKnownReturnIsRefreshedSavedAndAnnouncedAgainWhenItGainsADocument(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $before = SampleReturns::listedWithoutDocument('F1', 10, 'EN_COURS', self::T, null);
        $after = SampleReturns::listed('F1', 10, 'OK', self::T, null);
        $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$before], Samples::at('2022-02-01T11:30:00Z'), new RecordedEvents());
        $events = new RecordedEvents();

        $discovered = $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$after], Samples::at(Samples::NOW), $events);

        static::assertSame(0, $discovered);
        $tracked = self::stored($harness, $after);
        static::assertSame($after, $tracked->reference());
        static::assertSame(Samples::NOW, $tracked->updatedAt()->toIso8601());
        static::assertSame('2022-02-01T11:30:00Z', $tracked->firstSeenAt()->toIso8601());
        static::assertCount(1, $events->all());
        $announced = $events->all()[0];
        static::assertInstanceOf(ReturnAvailable::class, $announced);
        static::assertTrue($announced->reference->hasDocument());
    }

    public function testAReturnListedBeforeItsDepositIsLinkedOnceTheDepositKnowsTheFlow(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $reference = SampleReturns::listed('F1', 10, 'OK', self::T, null);
        $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$reference], Samples::at(Samples::NOW), new RecordedEvents());
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T);
        $deposit->startDeposit(Samples::at(self::T), 3);
        $deposit->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T));
        $harness->deposits->save($deposit);
        $before = self::stored($harness, $reference);

        $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$reference], Samples::at(Samples::NOW), new RecordedEvents());

        static::assertNull($before->depositId());
        static::assertSame('d1', self::stored($harness, $reference)->depositId()?->value());
    }

    public function testANewReturnIsLinkedToTheDepositOfItsFlowOfTheSameTenantOnly(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', self::T);
        $deposit->startDeposit(Samples::at(self::T), 3);
        $deposit->recordResult(SampleAnswers::receipt('F1'), Samples::at(self::T));
        $harness->deposits->save($deposit);
        $reference = SampleReturns::listed('F1', 10, 'OK', self::T, null);

        $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$reference], Samples::at(Samples::NOW), new RecordedEvents());
        $harness->ingestion->ingest(Samples::tenant('tenant-c'), [$reference], Samples::at(Samples::NOW), new RecordedEvents());

        static::assertSame('d1', self::stored($harness, $reference)->depositId()?->value());
        $other = $harness->returns->find(Samples::tenant('tenant-c'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $other);
        static::assertNull($other->depositId());
    }
}
