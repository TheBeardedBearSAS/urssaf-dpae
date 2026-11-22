<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmployeeDateAndNirTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideBirthDates(): iterable
    {
        yield 'yesterday' => ['2026-11-21 23:59:59', []];
        yield 'today at midnight' => ['2026-11-22 00:00:00', ['birthDate employee.birth_date_not_in_the_past 33']];
        yield 'today' => ['2026-11-22 07:00:00', ['birthDate employee.birth_date_not_in_the_past 33']];
        yield 'today, after now' => ['2026-11-22 23:59:59', ['birthDate employee.birth_date_not_in_the_past 33']];
        yield 'tomorrow' => ['2026-11-23', ['birthDate employee.birth_date_not_in_the_past 33']];
        yield 'last year' => ['2025-11-22', []];
        yield 'next month, earlier day' => ['2026-12-01', ['birthDate employee.birth_date_not_in_the_past 33']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideBirthDates')]
    public function testBirthDateMustBeBeforeToday(string $birth, array $expected): void
    {
        $errors = ExampleDeclaration::errors(
            ExampleEmployee::with(['birthDate' => new DateTimeImmutable($birth)])->validate(ExampleDeclaration::rules(), ExampleDeclaration::now()),
        );

        static::assertSame($expected, $errors);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideNirs(): iterable
    {
        yield 'twelve' => [str_repeat('1', 12), ['nir employee.nir_invalid_length 38']];
        yield 'thirteen' => [str_repeat('1', 13), []];
        yield 'thirteen multibyte characters' => [str_repeat('é', 13), []];
        yield 'fourteen' => [str_repeat('1', 14), ['nir employee.nir_invalid_length 38']];
        yield 'fifteen' => [str_repeat('1', 15), []];
        yield 'sixteen' => [str_repeat('1', 16), ['nir employee.nir_invalid_length 38']];
        yield 'empty' => ['', ['nir employee.nir_invalid_length 38']];
        yield 'letters are not checked' => ['2AB0000000000CD', []];
        yield 'official example' => ['100000000000099', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideNirs')]
    public function testNirLength(string $nir, array $expected): void
    {
        $errors = ExampleDeclaration::errors(
            ExampleEmployee::with(['nir' => $nir])->validate(ExampleDeclaration::rules(), ExampleDeclaration::now()),
        );

        static::assertSame($expected, $errors);
    }
}
