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
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\Event\ReturnAvailable;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class TrackedReturnTest extends TestCase
{
    use TrackedReturnFixtures;

    public function testADiscoveredReturnIsPendingAndAnnouncedOnce(): void
    {
        $tracked = self::tracked();

        static::assertSame('tenant-1', $tracked->tenantId()->value());
        static::assertSame('F1', $tracked->reference()->flowId()->value());
        static::assertTrue($tracked->identity()->equals($tracked->reference()->identity()));
        static::assertNull($tracked->depositId());
        static::assertSame(DownloadState::Pending, $tracked->downloadState());
        static::assertSame(self::T0, $tracked->firstSeenAt()->toIso8601());
        static::assertSame(self::T0, $tracked->updatedAt()->toIso8601());
        $events = $tracked->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(ReturnAvailable::class, $events[0]);
        static::assertSame('F1', $events[0]->reference->flowId()->value());
        static::assertSame(self::T0, $events[0]->occurredAt()->toIso8601());
        static::assertSame([], $tracked->releaseEvents());
    }

    public function testARefreshTakesTheLatestFormWithoutANewEventWhenADocumentWasAlreadyThere(): void
    {
        $tracked = self::tracked();
        $tracked->releaseEvents();
        $latest = SampleReturns::listed('F1', 10, 'OK', self::T0, null);

        $tracked->refresh($latest, Samples::at(self::T1));

        static::assertSame($latest, $tracked->reference());
        static::assertSame(self::T1, $tracked->updatedAt()->toIso8601());
        static::assertSame(self::T0, $tracked->firstSeenAt()->toIso8601());
        static::assertSame([], $tracked->releaseEvents());
    }

    public function testAReturnThatGainsADocumentIsAnnouncedAgain(): void
    {
        $tracked = self::trackedWithoutDocument();
        $tracked->releaseEvents();

        $tracked->refresh(SampleReturns::listed('F1', 10, 'OK', self::T0, null), Samples::at(self::T1));

        $events = $tracked->releaseEvents();
        static::assertCount(1, $events);
        static::assertInstanceOf(ReturnAvailable::class, $events[0]);
        static::assertTrue($events[0]->reference->hasDocument());
        static::assertSame(self::T1, $events[0]->occurredAt()->toIso8601());
    }

    public function testEveryRecordedEventIsReleasedOnceInOrder(): void
    {
        $tracked = self::trackedWithoutDocument();
        $tracked->refresh(SampleReturns::listed('F1', 10, 'OK', self::T0, null), Samples::at(self::T1));

        $events = $tracked->releaseEvents();

        static::assertCount(2, $events);
        static::assertInstanceOf(ReturnAvailable::class, $events[0]);
        static::assertInstanceOf(ReturnAvailable::class, $events[1]);
        static::assertFalse($events[0]->reference->hasDocument());
        static::assertTrue($events[1]->reference->hasDocument());
        static::assertSame([], $tracked->releaseEvents());
    }

    public function testAReturnStillWithoutDocumentIsNotAnnouncedAgain(): void
    {
        $tracked = self::trackedWithoutDocument();
        $tracked->releaseEvents();

        $tracked->refresh(SampleReturns::listedWithoutDocument('F1', 10, 'OK', self::T0, null), Samples::at(self::T1));

        static::assertSame([], $tracked->releaseEvents());
    }

    public function testARefreshOfAnotherReturnIsRefused(): void
    {
        $tracked = self::tracked();

        foreach ([['F2', 10, null], ['F1', 11, null], ['F1', 10, 'OPS01']] as [$flow, $nature, $ops]) {
            try {
                $tracked->refresh(SampleReturns::listed($flow, $nature, 'OK', self::T0, $ops), Samples::at(self::T1));
                static::fail('InvariantViolation expected.');
            } catch (InvariantViolation $e) {
                static::assertSame('return.identity_mismatch', $e->violationCode());
            }
        }
        static::assertSame(self::T0, $tracked->updatedAt()->toIso8601());
    }

    public function testTheDepositLinkIsSetOnceAndKept(): void
    {
        $tracked = self::tracked();

        $tracked->linkTo(DepositId::fromString('d1'));
        $tracked->linkTo(DepositId::fromString('d2'));

        static::assertSame('d1', $tracked->depositId()?->value());
    }
}
