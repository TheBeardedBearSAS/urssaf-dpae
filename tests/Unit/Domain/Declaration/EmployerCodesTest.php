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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmployerCodesTest extends TestCase
{
    /**
     * @param list<string> $violations
     *
     * @return list<string>
     */
    private static function phoneOnly(array $violations): array
    {
        return array_values(array_filter($violations, static fn(string $line): bool => str_starts_with($line, 'phone ')));
    }

    /**
     * @return list<string>
     */
    private static function expected(string $expected, string $field, string $path, string $normCode): array
    {
        return match ($expected) {
            'ok' => [],
            'empty' => [\sprintf('%s employer.%s_empty %s', $path, $field, $normCode)],
            default => [\sprintf('%s employer.%s_invalid_length %s', $path, $field, $normCode)],
        };
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideApeCodes(): iterable
    {
        yield 'three' => ['811', 'error'];
        yield 'four' => ['8110', 'ok'];
        yield 'five' => ['8110Z', 'ok'];
        yield 'six' => ['8110ZZ', 'error'];
        yield 'empty' => ['', 'empty'];
        yield 'blank' => [' ', 'empty'];
        yield 'padded' => [' 8110Z ', 'ok'];
        yield 'five multibyte characters' => ['éééé', 'ok'];
    }

    #[DataProvider('provideApeCodes')]
    public function testApeCodeLength(string $code, string $expected): void
    {
        $errors = ExampleDeclaration::errors(ExampleEmployer::with(['apeCode' => $code])->validate());

        static::assertSame(self::expected($expected, 'ape_code', 'apeCode', '03'), $errors);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideUrssafCodes(): iterable
    {
        yield 'empty' => ['', 'empty'];
        yield 'blank' => ["\t", 'empty'];
        yield 'padded' => [' 257 ', 'ok'];
        yield 'one' => ['2', 'ok'];
        yield 'three' => ['257', 'ok'];
        yield 'four' => ['2570', 'error'];
        yield 'three multibyte characters' => ['ééé', 'ok'];
    }

    #[DataProvider('provideUrssafCodes')]
    public function testUrssafCodeLength(string $code, string $expected): void
    {
        $errors = ExampleDeclaration::errors(ExampleEmployer::with(['urssafCode' => $code])->validate());

        static::assertSame(self::expected($expected, 'urssaf_code', 'urssafCode', '02'), $errors);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function providePhones(): iterable
    {
        yield 'ten digits' => ['0000000000', []];
        yield 'nine digits' => ['000000000', ['phone employer.phone_invalid_format 99']];
        yield 'eleven digits' => ['00000000000', ['phone employer.phone_invalid_format 99']];
        yield 'letters' => ['000000000A', ['phone employer.phone_invalid_format 99']];
        yield 'trailing newline' => ["0000000000\n", ['phone employer.phone_invalid_format 99']];
        yield 'leading space' => [' 0000000000', ['phone employer.phone_invalid_format 99']];
    }

    /**
     * @param list<string> $warnings
     */
    #[DataProvider('providePhones')]
    public function testPhone(string $phone, array $warnings): void
    {
        $result = ExampleEmployer::with(['phone' => $phone])->validate();

        static::assertSame($warnings, self::phoneOnly(ExampleDeclaration::warnings($result)));
        static::assertSame([], ExampleDeclaration::errors($result));
    }

    public function testThePhoneIsOptional(): void
    {
        static::assertSame([], self::phoneOnly(ExampleDeclaration::warnings(ExampleEmployer::with(['phone' => null])->validate())));
    }
}
