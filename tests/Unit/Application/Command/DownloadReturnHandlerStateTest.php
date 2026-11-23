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
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturn;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * What the download handler saves, asked once or again, on a repository that keeps copies like a database.
 */
final class DownloadReturnHandlerStateTest extends TestCase
{
    private static function tracked(Harness $harness): ReturnReference
    {
        $reference = SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null);
        $tracked = TrackedReturn::discover(Samples::tenant('tenant-1'), $reference, null, Samples::at('2022-02-01T11:00:00Z'));
        $tracked->releaseEvents();
        $harness->returns->save($tracked);

        return $reference;
    }

    private static function command(ReturnReference $reference): DownloadReturn
    {
        return new DownloadReturn(Samples::tenant('tenant-1'), $reference->identity());
    }

    public function testEveryStateChangeIsSavedWhenTheRepositoryKeepsCopies(): void
    {
        foreach ([
            [ReturnContent::of('<doc/>', 'application/xml'), DownloadState::Downloaded],
            [ReturnNoLongerAvailable::forReturn(SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null)), DownloadState::Purged],
            [ReturnNotDownloadable::withoutDocument(SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null)), DownloadState::NotDownloadable],
        ] as [$answer, $expected]) {
            $harness = Harness::snapshotting(WorkflowPolicy::defaults());
            $reference = self::tracked($harness);
            $harness->gateway->willDownload($answer);

            Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));

            static::assertSame($expected, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
        }
    }

    public function testAskingAgainForAPurgedReturnStillThrowsTheGatewayRefusal(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnNoLongerAvailable::forReturn($reference));
        $harness->gateway->willDownload(ReturnNoLongerAvailable::forReturn($reference));

        $first = Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));
        $second = Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));

        static::assertInstanceOf(ReturnNoLongerAvailable::class, $first);
        static::assertInstanceOf(ReturnNoLongerAvailable::class, $second);
        static::assertSame(DownloadState::Purged, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
    }

    public function testAskingAgainForAnUndownloadableReturnStillThrowsTheGatewayRefusal(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnNotDownloadable::withoutDocument($reference));
        $harness->gateway->willDownload(ReturnNotDownloadable::withoutDocument($reference));

        $first = Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));
        $second = Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));

        static::assertInstanceOf(ReturnNotDownloadable::class, $first);
        static::assertInstanceOf(ReturnNotDownloadable::class, $second);
        static::assertSame(DownloadState::NotDownloadable, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
    }

    public function testAReturnThatBecameDownloadableIsDownloadedAndKept(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnNotDownloadable::withoutDocument($reference));
        Outcome::of(static fn(): ReturnContent => $harness->downloadHandler()(self::command($reference)));
        $withDocument = SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:00:00Z', null);
        $harness->ingestion->ingest(Samples::tenant('tenant-1'), [$withDocument], Samples::at(Samples::NOW), new RecordedEvents());
        $content = ReturnContent::of('<doc/>', 'application/xml');
        $harness->gateway->willDownload($content);

        $downloaded = $harness->downloadHandler()(self::command($reference));

        static::assertSame($content, $downloaded);
        static::assertSame(DownloadState::Downloaded, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
    }

    public function testADownloadedReturnWhoseContentWasPurgedIsUnavailableWithoutACall(): void
    {
        $harness = Harness::snapshotting(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnContent::of('<doc/>', 'application/xml'));
        $harness->downloadHandler()(self::command($reference));
        $afterPurge = new DownloadReturnHandler($harness->returns, new InMemoryReturnContentStore(), $harness->gateway, $harness->clock, $harness->unitOfWork); // the documents were purged by the host

        $failure = Outcome::of(static fn(): ReturnContent => $afterPurge(self::command($reference)));

        static::assertInstanceOf(ReturnNoLongerAvailable::class, $failure);
        static::assertCount(1, $harness->gateway->downloadCalls());
        static::assertSame(DownloadState::Downloaded, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
    }
}
