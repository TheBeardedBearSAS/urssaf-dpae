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
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Contract\Event\ReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Harness;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class DownloadReturnHandlerTest extends TestCase
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

    public function testItDownloadsKeepsAndAnnouncesTheDocument(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $content = ReturnContent::of('<doc/>', 'application/xml');
        $harness->gateway->willDownload($content);

        $downloaded = $harness->downloadHandler()(self::command($reference));

        static::assertSame($content, $downloaded);
        static::assertSame($reference, $harness->gateway->downloadCalls()[0]);
        static::assertSame($content, $harness->contents->find(Samples::tenant('tenant-1'), $reference->identity()));
        $tracked = $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity());
        static::assertInstanceOf(TrackedReturn::class, $tracked);
        static::assertSame(DownloadState::Downloaded, $tracked->downloadState());
        static::assertSame(Samples::NOW, $tracked->updatedAt()->toIso8601());
        static::assertCount(1, $harness->outbox->events());
        static::assertInstanceOf(ReturnDownloaded::class, $harness->outbox->events()[0]);
        static::assertCount(1, $harness->events->events());
    }

    public function testAStoredDocumentIsReturnedWithoutACall(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $content = ReturnContent::of('<doc/>', 'application/xml');
        $harness->gateway->willDownload($content);
        $harness->downloadHandler()(self::command($reference));

        $again = $harness->downloadHandler()(self::command($reference));

        static::assertSame($content, $again);
        static::assertCount(1, $harness->gateway->downloadCalls());
    }

    public function testAnUnlistedReturnIsNotFound(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());

        try {
            $harness->downloadHandler()(new DownloadReturn(Samples::tenant('tenant-1'), SampleReturns::listed('F9', 10, 'OK', null, null)->identity()));
            static::fail('RecordNotFound expected.');
        } catch (RecordNotFound $e) {
            static::assertSame('return.not_found', $e->violationCode());
            static::assertSame('F9', $e->identifier());
        }
        static::assertSame([], $harness->gateway->downloadCalls());
    }

    public function testAPurgedReturnIsMarkedAndTheFailureRethrown(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnNoLongerAvailable::forReturn($reference));

        try {
            $harness->downloadHandler()(self::command($reference));
            static::fail('ReturnNoLongerAvailable expected.');
        } catch (ReturnNoLongerAvailable) {
            static::assertSame(DownloadState::Purged, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
            static::assertNull($harness->contents->find(Samples::tenant('tenant-1'), $reference->identity()));
        }
    }

    public function testAReturnWithoutUsableDocumentIsMarkedAndTheFailureRethrown(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(ReturnNotDownloadable::withoutDocument($reference));

        try {
            $harness->downloadHandler()(self::command($reference));
            static::fail('ReturnNotDownloadable expected.');
        } catch (ReturnNotDownloadable) {
            static::assertSame(DownloadState::NotDownloadable, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
        }
    }

    public function testATransientFailureLeavesTheReturnPending(): void
    {
        $harness = Harness::inMemory(WorkflowPolicy::defaults());
        $reference = self::tracked($harness);
        $harness->gateway->willDownload(UrssafUnavailable::withStatus(Operation::DownloadReturn, 503, null));

        try {
            $harness->downloadHandler()(self::command($reference));
            static::fail('UrssafUnavailable expected.');
        } catch (UrssafUnavailable) {
            static::assertSame(DownloadState::Pending, $harness->returns->find(Samples::tenant('tenant-1'), $reference->identity())?->downloadState());
        }
    }
}
