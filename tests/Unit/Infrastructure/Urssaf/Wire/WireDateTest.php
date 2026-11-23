<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

final class WireDateTest extends TestCase
{
    public function testItFormatsOnA24HourClockInParisByDefault(): void
    {
        $dates = WireDate::defaultZone();

        static::assertSame('20220130083000', $dates->format(Instant::fromIso8601('2022-01-30T07:30:00Z')));
        static::assertSame('20220715153000', $dates->format(Instant::fromIso8601('2022-07-15T13:30:00Z')));
        static::assertSame('20220715220500', $dates->format(Instant::fromIso8601('2022-07-15T20:05:00Z')));
    }

    public function testTheZoneIsConfigurable(): void
    {
        $dates = WireDate::inZone(new DateTimeZone('UTC'));

        static::assertSame('20220130073000', $dates->format(Instant::fromIso8601('2022-01-30T07:30:00Z')));
        static::assertSame('2022-01-30T07:30:00Z', $dates->parse('20220130073000')?->toIso8601());
        static::assertSame('2022-01-30T07:30:00Z', WireDate::defaultZone()->parse('20220130083000')?->toIso8601());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideReadableDates(): iterable
    {
        yield 'wire format, winter' => ['20220130083000', '2022-01-30T07:30:00Z'];
        yield 'wire format, summer' => ['20220715153000', '2022-07-15T13:30:00Z'];
        yield 'afternoon is 24-hour, not 12-hour' => ['20220130153000', '2022-01-30T14:30:00Z'];
        yield 'midnight' => ['20220130000000', '2022-01-29T23:00:00Z'];
        yield 'last second of the day' => ['20220130235959', '2022-01-30T22:59:59Z'];
        yield 'surrounding whitespace' => ["  20220130083000\n", '2022-01-30T07:30:00Z'];
        yield 'extended format with T' => ['2022-01-30T08:30:00', '2022-01-30T07:30:00Z'];
        yield 'extended format with a space' => ['2022-01-30 08:30:00', '2022-01-30T07:30:00Z'];
        yield 'fraction is dropped' => ['2022-01-30T08:30:00.123', '2022-01-30T07:30:00Z'];
        yield 'comma fraction' => ['20220130083000,5', '2022-01-30T07:30:00Z'];
        yield 'explicit Z wins over the zone' => ['2022-01-30T08:30:00Z', '2022-01-30T08:30:00Z'];
        yield 'explicit offset wins over the zone' => ['2022-01-30T08:30:00+05:00', '2022-01-30T03:30:00Z'];
        yield 'compact offset' => ['20220130083000-0130', '2022-01-30T10:00:00Z'];
        yield 'hour-only offset' => ['20220130083000+01', '2022-01-30T07:30:00Z'];
        yield 'clock hour repeated by the time change is read once' => ['20221030023000', '2022-10-30T01:30:00Z'];
        yield 'leap day' => ['20240229120000', '2024-02-29T11:00:00Z'];
    }

    #[DataProvider('provideReadableDates')]
    public function testItReadsDatesLeniently(string $value, string $expected): void
    {
        static::assertSame($expected, WireDate::defaultZone()->parse($value)?->toIso8601());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableDates(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['pas une date'];
        yield 'too short' => ['2022013008300'];
        yield 'too long' => ['202201300830001'];
        yield 'date only' => ['20220130'];
        yield 'twelve digits' => ['202201300830'];
        yield 'text around' => ['x20220130083000'];
        yield 'text after' => ['20220130083000x'];
        yield 'trailing second line' => ["20220130083000\nx"];
        yield 'month 13' => ['20221301000000'];
        yield 'day 30 of February' => ['20220230083000'];
        yield 'day 29 of February in a common year' => ['20230229083000'];
        yield 'hour 24' => ['20220130240000'];
        yield 'minute 60' => ['20220130086000'];
        yield 'second 60' => ['20220130083060'];
        yield 'hour skipped by the time change' => ['20220327023000'];
        yield 'impossible offset' => ['20220130083000+99:99'];
    }

    #[DataProvider('provideUnreadableDates')]
    public function testItReturnsNullForAnUnreadableDate(string $value): void
    {
        static::assertNull(WireDate::defaultZone()->parse($value));
    }
}
