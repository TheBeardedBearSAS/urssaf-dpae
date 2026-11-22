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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class TrackedReturnDownloadStateTest extends TestCase
{
    use TrackedReturnFixtures;

    public function testASuccessfulDownloadOfAReturnThoughtNotDownloadableIsKept(): void
    {
        $tracked = self::tracked();
        $tracked->markNotDownloadable(Samples::at(self::T1));

        $tracked->markDownloaded(Samples::at('2022-02-01T12:00:00Z'));

        static::assertSame(DownloadState::Downloaded, $tracked->downloadState());
    }

    public function testAReturnThatStaysWithoutDocumentStaysNotDownloadableAndAPurgedOneStaysPurged(): void
    {
        $notDownloadable = self::trackedWithoutDocument();
        $notDownloadable->markNotDownloadable(Samples::at(self::T1));
        $purged = self::trackedWithoutDocument();
        $purged->markPurged(Samples::at(self::T1));

        $notDownloadable->refresh(SampleReturns::listedWithoutDocument('F1', 10, 'OK', self::T0, null), Samples::at('2022-02-01T12:00:00Z'));
        $purged->refresh(SampleReturns::listed('F1', 10, 'OK', self::T0, null), Samples::at('2022-02-01T12:00:00Z'));

        static::assertSame(DownloadState::NotDownloadable, $notDownloadable->downloadState());
        static::assertSame(DownloadState::Purged, $purged->downloadState());
    }

    #[DataProvider('transitions')]
    public function testADownloadStateIsFinal(string $second): void
    {
        $tracked = self::tracked();
        $tracked->markDownloaded(Samples::at(self::T1));

        try {
            self::attempt($tracked, $second);
            static::fail('InvalidStateTransition expected.');
        } catch (InvalidStateTransition $e) {
            static::assertSame('tracked_return.invalid_state_transition', $e->violationCode());
            static::assertSame('downloaded', $e->from());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function transitions(): iterable
    {
        yield 'downloaded again' => ['downloaded'];
        yield 'not downloadable' => ['not_downloadable'];
        yield 'purged' => ['purged'];
    }

    /**
     * @throws InvalidStateTransition
     */
    private static function attempt(TrackedReturn $tracked, string $transition): void
    {
        $now = Samples::at(self::T1);
        match ($transition) {
            'downloaded' => $tracked->markDownloaded($now),
            'not_downloadable' => $tracked->markNotDownloadable($now),
            'purged' => $tracked->markPurged($now),
            default => static::fail('Unknown transition.'),
        };
    }
}
