<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\RetryAfter;

final class RetryAfterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function provideValues(): iterable
    {
        yield 'seconds' => ['120', 120];
        yield 'zero' => ['0', 0];
        yield 'seconds with spaces' => [' 30 ', 30];
        yield 'nine digits' => ['999999999', 999_999_999];
        yield 'ten digits' => ['1000000000', null];
        yield 'negative' => ['-5', null];
        yield 'fraction' => ['1.5', null];
        yield 'trailing text' => ['12 s', null];
        yield 'trailing newline' => ["12\n", 12];
        yield 'empty' => ['', null];
        yield 'text' => ['soon', null];
        yield 'http date in the future' => ['Sun, 06 Nov 1994 08:50:37 GMT', 60];
        yield 'http date now' => ['Sun, 06 Nov 1994 08:49:37 GMT', 0];
        yield 'http date in the past' => ['Sun, 06 Nov 1994 08:00:00 GMT', 0];
        yield 'http date with another zone name' => ['Sun, 06 Nov 1994 08:50:37 CET', null];
        yield 'impossible http date' => ['Sun, 31 Feb 1994 08:50:37 GMT', null];
        yield 'http date with trailing text' => ['Sun, 06 Nov 1994 08:50:37 GMT later', null];
    }

    #[DataProvider('provideValues')]
    public function testItReadsSecondsOrAnHttpDate(string $value, ?int $expected): void
    {
        $now = new DateTimeImmutable('1994-11-06 08:49:37', new DateTimeZone('UTC'));

        static::assertSame($expected, RetryAfter::seconds($value, $now));
    }

    public function testTheHttpDateIsCountedInUtcWhateverTheZoneOfTheClock(): void
    {
        $now = new DateTimeImmutable('1994-11-06 09:49:37', new DateTimeZone('Europe/Paris'));

        static::assertSame(60, RetryAfter::seconds('Sun, 06 Nov 1994 08:50:37 GMT', $now));
    }
}
