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
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnId;

final class ReturnIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAccepted(): iterable
    {
        yield 'one character' => ['a'];
        yield 'ten characters' => ['0123456789'];
        yield 'ten multibyte characters' => [str_repeat('é', 10)];
        yield 'blank is a value' => [' '];
    }

    #[DataProvider('provideAccepted')]
    public function testTheBoundsAreAccepted(string $value): void
    {
        static::assertSame($value, ReturnId::fromString($value)->value());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefused(): iterable
    {
        yield 'empty' => ['', 'return_id.empty'];
        yield 'eleven characters' => ['01234567890', 'return_id.too_long'];
        yield 'eleven multibyte characters' => [str_repeat('é', 11), 'return_id.too_long'];
        yield 'invalid utf-8' => ["\xFF", 'return_id.invalid_format'];
        yield 'invalid utf-8 at the maximum length' => ["\xFF" . str_repeat('a', 9), 'return_id.invalid_format'];
    }

    #[DataProvider('provideRefused')]
    public function testOutOfBoundsValuesAreRefused(string $value, string $code): void
    {
        try {
            ReturnId::fromString($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }

    public function testItIsComparedByValue(): void
    {
        static::assertTrue(ReturnId::fromString('abc')->equals(ReturnId::fromString('abc')));
        static::assertFalse(ReturnId::fromString('abc')->equals(ReturnId::fromString('abd')));
    }
}
