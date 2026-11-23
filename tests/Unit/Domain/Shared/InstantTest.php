<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final class InstantTest extends TestCase
{
    public function testItNormalisesToUtcAndTruncatesToTheSecond(): void
    {
        $instant = Instant::fromIso8601('2022-02-01T17:40:00.987+01:00');

        static::assertSame('2022-02-01T16:40:00Z', $instant->toIso8601());
        static::assertSame('2022-02-01T16:40:00Z', (string) $instant);
        static::assertSame('UTC', $instant->toDateTimeImmutable()->getTimezone()->getName());
    }

    public function testItIsBuiltFromAnyDateTime(): void
    {
        $instant = Instant::fromDateTime(new DateTimeImmutable('2022-02-01 17:40:00', new DateTimeZone('Europe/Paris')));

        static::assertSame('2022-02-01T16:40:00Z', $instant->toIso8601());
    }

    public function testItConvertsToATimeZone(): void
    {
        $paris = Instant::fromIso8601('2022-07-01T10:00:00Z')->inTimeZone(new DateTimeZone('Europe/Paris'));

        static::assertSame('2022-07-01 12:00:00', $paris->format('Y-m-d H:i:s'));
    }

    public function testItComparesToTheSecond(): void
    {
        $a = Instant::fromIso8601('2022-02-01T16:40:00Z');
        $sameWithFraction = Instant::fromIso8601('2022-02-01T16:40:00.900Z');
        $later = Instant::fromIso8601('2022-02-01T16:40:01Z');

        static::assertTrue($a->equals($sameWithFraction));
        static::assertFalse($a->equals($later));
        static::assertTrue($a->isBefore($later));
        static::assertFalse($later->isBefore($a));
        static::assertFalse($a->isBefore($sameWithFraction));
        static::assertTrue($later->isAfter($a));
        static::assertFalse($a->isAfter($later));
        static::assertFalse($a->isAfter($sameWithFraction));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'no offset' => ['2022-02-01T16:40:00'];
        yield 'date only' => ['2022-02-01'];
        yield 'trailing garbage' => ['2022-02-01T16:40:00Z '];
        yield 'leading garbage' => [' 2022-02-01T16:40:00Z'];
        yield 'empty' => [''];
    }

    #[DataProvider('malformed')]
    public function testItRejectsMalformedValues(string $value): void
    {
        try {
            Instant::fromIso8601($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('instant.invalid_format', $e->violationCode());
        }
    }

    public function testItRejectsADateThatDoesNotExist(): void
    {
        try {
            Instant::fromIso8601('2022-02-30T16:40:00Z');
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('instant.invalid_date', $e->violationCode());
        }
    }
}
