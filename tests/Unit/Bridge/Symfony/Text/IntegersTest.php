<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Text;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Integers;

final class IntegersTest extends TestCase
{
    #[DataProvider('integers')]
    public function testItReadsAnIntegerWrittenAsText(string $text, ?int $expected): void
    {
        static::assertSame($expected, Integers::parse($text));
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function integers(): iterable
    {
        yield 'zero' => ['0', 0];
        yield 'positive' => ['25', 25];
        yield 'negative' => ['-3', -3];
        yield 'many digits' => ['1234567890', 1_234_567_890];
        yield 'empty' => ['', null];
        yield 'sign only' => ['-', null];
        yield 'leading zero' => ['01', null];
        yield 'plus sign' => ['+1', null];
        yield 'fraction' => ['1.5', null];
        yield 'space before' => [' 1', null];
        yield 'space after' => ['1 ', null];
        yield 'trailing newline' => ["1\n", null];
        yield 'text' => ['abc', null];
        yield 'text after digits' => ['12a', null];
        yield 'text before digits' => ['a12', null];
        yield 'negative zero' => ['-0', 0];
    }
}
