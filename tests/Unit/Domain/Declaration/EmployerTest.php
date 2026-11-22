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

final class EmployerTest extends TestCase
{
    public function testGettersAndTheValidExample(): void
    {
        $employer = ExampleEmployer::with([]);

        static::assertSame('99999999999999', $employer->siret()->value());
        static::assertSame('8110Z', $employer->apeCode());
        static::assertSame('257', $employer->urssafCode());
        static::assertSame('44 rue DES TEST', $employer->street());
        static::assertSame('CAEN', $employer->town());
        static::assertSame('14000', $employer->postalCode());
        static::assertSame('0000000000', $employer->phone());
        static::assertNull($employer->companyName());
        static::assertSame([], ExampleDeclaration::errors($employer->validate()));
    }

    public function testAMissingCompanyNameIsAWarningNotAnError(): void
    {
        $result = ExampleEmployer::with(['companyName' => null])->validate();

        static::assertTrue($result->isValid());
        static::assertSame(
            ['companyName employer.company_name_missing 05'],
            ExampleDeclaration::warnings($result),
        );
    }

    public function testAGivenCompanyNameIsCheckedButNotWritten(): void
    {
        $result = ExampleEmployer::with(['companyName' => 'ACME & Fils (Caen)'])->validate();

        static::assertTrue($result->isValid());
        static::assertSame(
            ['companyName employer.company_name_not_serialized 05'],
            ExampleDeclaration::warnings($result),
        );
    }

    public function testAnInvalidCompanyNameIsAnError(): void
    {
        $result = ExampleEmployer::with(['companyName' => ''])->validate();

        static::assertSame(['companyName employer.company_name_empty 05'], ExampleDeclaration::errors($result));
        static::assertSame(
            ['companyName employer.company_name_not_serialized 05'],
            ExampleDeclaration::warnings($result),
        );
    }

    public function testEveryViolationIsCollectedInTheOrderOfTheChecks(): void
    {
        $result = ExampleEmployer::with([
            'apeCode' => '1',
            'urssafCode' => '',
            'street' => '',
            'town' => '',
            'postalCode' => '1',
            'phone' => '1',
        ])->validate();

        static::assertSame(
            [
                'apeCode employer.ape_code_invalid_length 03',
                'urssafCode employer.urssaf_code_empty 02',
                'street employer.street_empty 06',
                'town employer.town_empty 08',
                'postalCode employer.postal_code_invalid_format 07',
            ],
            ExampleDeclaration::errors($result),
        );
        static::assertContains('phone employer.phone_invalid_format 99', ExampleDeclaration::warnings($result));
    }

    public function testDebugInfoShowsTheSiretOnly(): void
    {
        static::assertSame(['siret' => '99999999999999'], ExampleEmployer::with([])->__debugInfo());
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(PayloadNotSerializable::class);

        serialize(ExampleEmployer::with([]));
    }

    public function testItCannotBeUnserialized(): void
    {
        $employer = ExampleEmployer::with([]);

        $this->expectException(PayloadNotSerializable::class);

        $employer->__unserialize([]);
    }
}
