<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnDownloaded;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class TrackedReturnDownloadTest extends TestCase
{
    use TrackedReturnFixtures;

    public function testADownloadedReturnIsAnnounced(): void
    {
        $tracked = TrackedReturn::discover(
            Samples::tenant('tenant-1'),
            SampleReturns::listed('F1', 10, 'OK', self::T0, null),
            DepositId::fromString('d1'),
            Samples::at(self::T0),
        );
        $tracked->releaseEvents();

        $tracked->markDownloaded(Samples::at(self::T1));

        static::assertSame(DownloadState::Downloaded, $tracked->downloadState());
        static::assertSame(self::T1, $tracked->updatedAt()->toIso8601());
        $events = $tracked->releaseEvents();
        static::assertInstanceOf(ReturnDownloaded::class, $events[0]);
        static::assertSame('d1', $events[0]->depositId?->value());
        static::assertSame(self::T1, $events[0]->occurredAt()->toIso8601());
    }

    public function testAReturnCanBeMarkedNotDownloadableOrPurged(): void
    {
        $notDownloadable = self::trackedWithoutDocument();
        $notDownloadable->markNotDownloadable(Samples::at(self::T1));
        $purged = self::tracked();
        $purged->markPurged(Samples::at(self::T1));

        static::assertSame(DownloadState::NotDownloadable, $notDownloadable->downloadState());
        static::assertSame(DownloadState::Purged, $purged->downloadState());
        static::assertSame(self::T1, $purged->updatedAt()->toIso8601());
    }

    public function testMarkingTwiceChangesNothing(): void
    {
        $notDownloadable = self::trackedWithoutDocument();
        $notDownloadable->markNotDownloadable(Samples::at(self::T1));
        $notDownloadable->markNotDownloadable(Samples::at('2022-02-01T12:00:00Z'));
        $purged = self::tracked();
        $purged->markPurged(Samples::at(self::T1));
        $purged->markPurged(Samples::at('2022-02-01T12:00:00Z'));

        static::assertSame(DownloadState::NotDownloadable, $notDownloadable->downloadState());
        static::assertSame(self::T1, $notDownloadable->updatedAt()->toIso8601());
        static::assertSame(DownloadState::Purged, $purged->downloadState());
        static::assertSame(self::T1, $purged->updatedAt()->toIso8601());
    }

    public function testAReturnWithoutDocumentMayBePurgedToo(): void
    {
        $tracked = self::trackedWithoutDocument();
        $tracked->markNotDownloadable(Samples::at(self::T1));

        $tracked->markPurged(Samples::at('2022-02-01T12:00:00Z'));

        static::assertSame(DownloadState::Purged, $tracked->downloadState());
    }

    public function testAReturnThatGainsADocumentCanBeDownloadedAgain(): void
    {
        $tracked = self::trackedWithoutDocument();
        $tracked->markNotDownloadable(Samples::at(self::T1));

        $tracked->refresh(SampleReturns::listed('F1', 10, 'OK', self::T0, null), Samples::at('2022-02-01T12:00:00Z'));

        static::assertSame(DownloadState::Pending, $tracked->downloadState());
        $tracked->markDownloaded(Samples::at('2022-02-01T12:00:00Z'));
        static::assertSame(DownloadState::Downloaded, $tracked->downloadState());
    }
}
