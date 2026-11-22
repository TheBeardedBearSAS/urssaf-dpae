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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;

final class EmployeeTest extends TestCase
{
    public function testGettersAndTheValidExample(): void
    {
        $employee = ExampleEmployee::with([]);

        static::assertSame('TEST', $employee->surname());
        static::assertSame('Alain', $employee->firstName());
        static::assertSame('1', $employee->sexCode());
        static::assertSame('100000000000099', $employee->nir());
        static::assertSame('1970-01-01', $employee->birthDate()->format('Y-m-d'));
        static::assertSame('PARIS', $employee->birthTown());
        static::assertSame('75', $employee->birthDepartment());
        static::assertSame([], ExampleDeclaration::errors($employee->validate(ExampleDeclaration::rules(), ExampleDeclaration::now())));
    }

    public function testTheNirIsSplitInIdentifierAndKey(): void
    {
        $fifteen = ExampleEmployee::with(['nir' => '100000000000099']);
        $thirteen = ExampleEmployee::with(['nir' => '1000000000000']);
        $none = ExampleEmployee::with(['nir' => null]);

        static::assertSame('1000000000000', $fifteen->nirIdentifier());
        static::assertSame('99', $fifteen->nirKey());
        static::assertSame('1000000000000', $thirteen->nirIdentifier());
        static::assertNull($thirteen->nirKey());
        static::assertNull($none->nirIdentifier());
        static::assertNull($none->nirKey());
    }

    public function testTheNirIsSplitByCharactersNotBytes(): void
    {
        $employee = ExampleEmployee::with(['nir' => 'ééééééééééééééé']);

        static::assertSame('ééééééééééééé', $employee->nirIdentifier());
        static::assertSame('éé', $employee->nirKey());
        static::assertNull(ExampleEmployee::with(['nir' => 'ééééééééééééé'])->nirKey());
    }

    public function testTheNirAndTheSexCodeAreOptional(): void
    {
        $employee = ExampleEmployee::with(['nir' => null, 'sexCode' => null]);

        static::assertSame([], ExampleDeclaration::errors($employee->validate(ExampleDeclaration::rules(), ExampleDeclaration::now())));
        static::assertNull($employee->sexCode());
    }

    public function testEveryViolationIsCollectedInTheOrderOfTheChecks(): void
    {
        $employee = ExampleEmployee::with([
            'surname' => '',
            'firstName' => '',
            'nir' => '1',
            'birthDate' => ExampleDeclaration::now(),
            'birthTown' => '',
            'birthDepartment' => '',
        ]);

        static::assertSame(
            [
                'surname employee.surname_empty 31',
                'firstName employee.first_name_empty 32',
                'birthTown employee.birth_town_empty 34',
                'birthDepartment employee.birth_department_empty 47',
                'birthDate employee.birth_date_not_in_the_past 33',
                'nir employee.nir_invalid_length 38',
            ],
            ExampleDeclaration::errors($employee->validate(ExampleDeclaration::rules(), ExampleDeclaration::now())),
        );
    }

    public function testPersonalDataIsHiddenFromDumps(): void
    {
        static::assertSame(['employee' => '***'], ExampleEmployee::with([])->__debugInfo());
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(PayloadNotSerializable::class);

        serialize(ExampleEmployee::with([]));
    }

    public function testItCannotBeUnserialized(): void
    {
        $employee = ExampleEmployee::with([]);

        $this->expectException(PayloadNotSerializable::class);

        $employee->__unserialize([]);
    }
}
