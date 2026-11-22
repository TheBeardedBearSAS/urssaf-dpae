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
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

final class EmployerTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideTexts(): iterable
    {
        yield 'empty' => ['', ['_empty']];
        yield 'blank' => ['   ', ['_empty']];
        yield 'thirty-two' => [str_repeat('A', 32), []];
        yield 'thirty-three' => [str_repeat('A', 33), ['_too_long']];
        yield 'thirty-two accented characters' => [str_repeat('é', 32), []];
        yield 'thirty-three accented characters' => [str_repeat('é', 33), ['_too_long']];
        yield 'allowed set, letters' => ["aZ09éèêë âàä öô ûü îï ç°²", []];
        yield 'allowed set, punctuation' => ["!#\$%&'()*+,-./:;<=>?@", []];
        yield 'double quote' => ['A"B', ['_invalid_characters']];
        yield 'leading disallowed' => ['"AB', ['_invalid_characters']];
        yield 'trailing disallowed' => ['AB"', ['_invalid_characters']];
        yield 'bytes of allowed characters' => ["\u{00AA}", ['_invalid_characters']];
        yield 'trailing newline' => ["AB\n", ['_invalid_characters']];
        yield 'too long and disallowed' => [str_repeat('"', 33), ['_too_long', '_invalid_characters']];
    }

    /**
     * @param list<string> $suffixes
     */
    #[DataProvider('provideTexts')]
    public function testStreetAndTownShareTheEmployerCharacterSet(string $value, array $suffixes): void
    {
        $street = ExampleDeclaration::errors(ExampleEmployer::with(['street' => $value])->validate());
        $town = ExampleDeclaration::errors(ExampleEmployer::with(['town' => $value])->validate());

        static::assertSame(array_map(static fn(string $s): string => 'street employer.street' . $s . ' 06', $suffixes), $street);
        static::assertSame(array_map(static fn(string $s): string => 'town employer.town' . $s . ' 08', $suffixes), $town);
    }

    public function testTooLongViolationCarriesTheLimit(): void
    {
        $errors = ExampleEmployer::with(['street' => str_repeat('A', 33)])->validate()->errors();

        static::assertCount(1, $errors);
        static::assertSame(['normCode' => '06', 'max_length' => 32], $errors[0]->params());
        static::assertSame('The street must not exceed 32 characters.', $errors[0]->message());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function providePostalCodes(): iterable
    {
        yield 'valid' => ['14000', []];
        yield 'four digits' => ['1400', ['employer.postal_code_invalid_format']];
        yield 'six digits' => ['140000', ['employer.postal_code_invalid_format']];
        yield 'letters' => ['1400A', ['employer.postal_code_invalid_format']];
        yield 'empty' => ['', ['employer.postal_code_invalid_format']];
        yield 'trailing newline' => ["14000\n", ['employer.postal_code_invalid_format']];
        yield 'leading space' => [' 14000', ['employer.postal_code_invalid_format']];
        yield 'zeros' => ['00000', ['employer.postal_code_unknown']];
        yield 'almost zeros' => ['00001', []];
    }

    /**
     * @param list<string> $codes
     */
    #[DataProvider('providePostalCodes')]
    public function testPostalCode(string $value, array $codes): void
    {
        $errors = ExampleEmployer::with(['postalCode' => $value])->validate()->errors();

        static::assertSame($codes, array_map(static fn(Violation $violation): string => $violation->code(), $errors));
        foreach ($errors as $error) {
            static::assertSame('postalCode', $error->path());
            static::assertSame(['normCode' => '07'], $error->params());
        }
    }
}
