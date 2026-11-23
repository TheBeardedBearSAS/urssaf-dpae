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
use TheBeardedBear\UrssafDpae\Application\Service\ClaimWindow;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SampleReturns;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ClaimWindowTest extends TestCase
{
    private static function window(string $claimedAt): ClaimWindow
    {
        $deposit = Samples::deposit('d1', 'tenant-1', 'ref-1', 'a', $claimedAt);
        $deposit->startDeposit(Samples::at($claimedAt), 3);

        return ClaimWindow::of($deposit, WorkflowPolicy::defaults());
    }

    public function testTheWindowSpansFromTheSkewBeforeToTheWindowAfterTheClaim(): void
    {
        $window = self::window('2022-02-01T11:30:00Z');

        static::assertSame('2022-02-01T11:30:00Z', $window->claimedAt->toIso8601());
        static::assertSame('2022-02-01T11:25:00Z', $window->start->toIso8601());
        static::assertSame('2022-02-01T12:30:00Z', $window->end->toIso8601());
        static::assertSame('d1', $window->deposit->id()->value());
    }

    public function testBothBoundsAreIncluded(): void
    {
        $window = self::window('2022-02-01T11:30:00Z');

        static::assertFalse($window->contains(Samples::at('2022-02-01T11:24:59Z')));
        static::assertTrue($window->contains(Samples::at('2022-02-01T11:25:00Z')));
        static::assertTrue($window->contains(Samples::at('2022-02-01T12:30:00Z')));
        static::assertFalse($window->contains(Samples::at('2022-02-01T12:30:01Z')));
    }

    public function testAReturnWithoutParsablePublicationIsNeverInTheWindow(): void
    {
        $window = self::window('2022-02-01T11:30:00Z');

        static::assertTrue($window->containsPublicationOf(SampleReturns::listed('F1', 10, 'OK', '2022-02-01T11:30:00Z', null)));
        static::assertFalse($window->containsPublicationOf(SampleReturns::listed('F1', 10, 'OK', '2022-02-01T14:00:00Z', null)));
        static::assertFalse($window->containsPublicationOf(SampleReturns::listed('F1', 10, 'OK', null, null)));
    }

    public function testTheSpanCoversEveryWindow(): void
    {
        $early = self::window('2022-02-01T08:00:00Z');
        $middle = self::window('2022-02-01T09:00:00Z');
        $late = self::window('2022-02-01T11:30:00Z');

        [$start, $end] = ClaimWindow::span([$middle, $late, $early]);
        [$onlyStart, $onlyEnd] = ClaimWindow::span([$middle]);
        [$orderedStart, $orderedEnd] = ClaimWindow::span([$early, $middle, $late]);

        static::assertSame('2022-02-01T07:55:00Z', $start->toIso8601());
        static::assertSame('2022-02-01T12:30:00Z', $end->toIso8601());
        static::assertSame('2022-02-01T08:55:00Z', $onlyStart->toIso8601());
        static::assertSame('2022-02-01T10:00:00Z', $onlyEnd->toIso8601());
        static::assertSame('2022-02-01T07:55:00Z', $orderedStart->toIso8601());
        static::assertSame('2022-02-01T12:30:00Z', $orderedEnd->toIso8601());
    }
}
