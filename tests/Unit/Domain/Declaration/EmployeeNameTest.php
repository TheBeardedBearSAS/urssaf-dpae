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
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationRules;

final class EmployeeNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideNames(): iterable
    {
        yield 'official example, mixed case, rejected by the strict default' => ['Alain', ['_invalid_characters']];
        yield 'upper case' => ['TEST', []];
        yield 'allowed punctuation' => ["JEAN-PIERRE D'ARC & CIE.", []];
        yield 'thirty-two' => [str_repeat('A', 32), []];
        yield 'thirty-three' => [str_repeat('A', 33), ['_too_long']];
        yield 'empty' => ['', ['_empty']];
        yield 'blank' => [' ', ['_empty']];
        yield 'accent' => ['Élodie', ['_invalid_characters']];
        yield 'digit at the end' => ['AB1', ['_invalid_characters']];
        yield 'digit at the start' => ['1AB', ['_invalid_characters']];
        yield 'digit inside' => ['A1B', ['_invalid_characters']];
        yield 'trailing newline' => ["AB\n", ['_invalid_characters']];
        yield 'too long and invalid' => [str_repeat('1', 33), ['_too_long', '_invalid_characters']];
    }

    /**
     * @param list<string> $suffixes
     */
    #[DataProvider('provideNames')]
    public function testSurnameAndFirstName(string $value, array $suffixes): void
    {
        $rules = DeclarationRules::defaults();

        $surname = ExampleDeclaration::errors(ExampleEmployee::with(['surname' => $value, 'firstName' => 'TEST'])->validate($rules, ExampleDeclaration::now()));
        $firstName = ExampleDeclaration::errors(ExampleEmployee::with(['surname' => 'TEST', 'firstName' => $value])->validate($rules, ExampleDeclaration::now()));

        static::assertSame(array_map(static fn(string $s): string => 'surname employee.surname' . $s . ' 31', $suffixes), $surname);
        static::assertSame(array_map(static fn(string $s): string => 'firstName employee.first_name' . $s . ' 32', $suffixes), $firstName);
    }

    public function testTheNamePatternIsConfigurable(): void
    {
        $accents = DeclarationRules::of("/^[\\p{L}' .&-]+\$/uD");

        static::assertSame(
            ['firstName employee.first_name_invalid_characters 32'],
            ExampleDeclaration::errors(ExampleEmployee::with([])->validate(DeclarationRules::defaults(), ExampleDeclaration::now())),
        );
        static::assertSame(
            [],
            ExampleDeclaration::errors(ExampleEmployee::with([])->validate(ExampleDeclaration::rules(), ExampleDeclaration::now())),
        );
        static::assertSame(
            [],
            ExampleDeclaration::errors(ExampleEmployee::with(['surname' => 'Éloïse'])->validate($accents, ExampleDeclaration::now())),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideBirthTowns(): iterable
    {
        yield 'valid' => ['PARIS', []];
        yield 'allowed set' => ["ST-DENIS 2E. L'ILE", []];
        yield 'thirty-two' => [str_repeat('A', 32), []];
        yield 'thirty-three' => [str_repeat('A', 33), ['_too_long']];
        yield 'empty' => ['', ['_empty']];
        yield 'lower case' => ['Paris', ['_invalid_characters']];
        yield 'leading disallowed' => ['éPARIS', ['_invalid_characters']];
        yield 'trailing disallowed' => ['PARISé', ['_invalid_characters']];
        yield 'trailing newline' => ["PARIS\n", ['_invalid_characters']];
    }

    /**
     * @param list<string> $suffixes
     */
    #[DataProvider('provideBirthTowns')]
    public function testBirthTown(string $value, array $suffixes): void
    {
        $errors = ExampleDeclaration::errors(
            ExampleEmployee::with(['birthTown' => $value])->validate(ExampleDeclaration::rules(), ExampleDeclaration::now()),
        );

        static::assertSame(array_map(static fn(string $s): string => 'birthTown employee.birth_town' . $s . ' 34', $suffixes), $errors);
    }

    public function testBirthDepartmentIsMandatory(): void
    {
        $rules = ExampleDeclaration::rules();

        static::assertSame(
            ['birthDepartment employee.birth_department_empty 47'],
            ExampleDeclaration::errors(ExampleEmployee::with(['birthDepartment' => ''])->validate($rules, ExampleDeclaration::now())),
        );
        static::assertSame(
            ['birthDepartment employee.birth_department_empty 47'],
            ExampleDeclaration::errors(ExampleEmployee::with(['birthDepartment' => ' '])->validate($rules, ExampleDeclaration::now())),
        );
        static::assertSame(
            [],
            ExampleDeclaration::errors(ExampleEmployee::with(['birthDepartment' => '2A'])->validate($rules, ExampleDeclaration::now())),
        );
    }
}
