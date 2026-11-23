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

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Service\ListingWindows;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class ListingWindowsTest extends TestCase
{
    /**
     * @param list<array{Instant, Instant}> $windows
     *
     * @return list<string>
     */
    private static function iso(array $windows): array
    {
        return array_map(static fn(array $window): string => $window[0]->toIso8601() . '/' . $window[1]->toIso8601(), $windows);
    }

    public function testNothingIsListedWhenThePeriodIsEmptyOrInverted(): void
    {
        $at = Samples::at('2022-02-01T10:00:00Z');

        static::assertSame([], ListingWindows::split($at, $at, null));
        static::assertSame([], ListingWindows::split($at, $at, new DateInterval('PT1H')));
        static::assertSame([], ListingWindows::split(Samples::at('2022-02-01T11:00:00Z'), $at, null));
    }

    public function testThePeriodIsOneRangeWhenNoLimitIsKnown(): void
    {
        $windows = ListingWindows::split(Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T15:30:00Z'), null);

        static::assertSame(['2022-02-01T10:00:00Z/2022-02-01T15:30:00Z'], self::iso($windows));
    }

    public function testThePeriodIsCutInRangesOfAtMostTheLimit(): void
    {
        $windows = ListingWindows::split(Samples::at('2022-02-01T10:00:00Z'), Samples::at('2022-02-01T12:30:00Z'), new DateInterval('PT1H'));

        static::assertSame([
            '2022-02-01T10:00:00Z/2022-02-01T11:00:00Z',
            '2022-02-01T11:00:00Z/2022-02-01T12:00:00Z',
            '2022-02-01T12:00:00Z/2022-02-01T12:30:00Z',
        ], self::iso($windows));
    }
}
