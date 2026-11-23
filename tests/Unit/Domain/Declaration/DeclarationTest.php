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
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;

final class DeclarationTest extends TestCase
{
    public function testTheOfficialExampleIsAValidDeclarationWithTheCompanyNameWarning(): void
    {
        $declaration = ExampleDeclaration::declaration();

        static::assertSame('99999999999999', $declaration->employer()->siret()->value());
        static::assertSame('TEST', $declaration->employee()->surname());
        static::assertSame(ContractType::Cdi, $declaration->contract()->type());
        static::assertSame(
            ['employer.companyName employer.company_name_missing 05'],
            array_map(ExampleDeclaration::describe(...), $declaration->warnings()),
        );
    }

    public function testViolationsAreNestedUnderTheirSubject(): void
    {
        $result = Declaration::validate(
            ExampleEmployer::with(['street' => '']),
            ExampleEmployee::with(['surname' => '']),
            ExampleContract::with(['healthService' => null]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        static::assertFalse($result->isValid());
        static::assertSame(
            [
                'employer.street employer.street_empty 06',
                'employee.surname employee.surname_empty 31',
                'contract.healthService contract.health_service_empty 50',
            ],
            ExampleDeclaration::errors($result),
        );
        static::assertSame(
            ['employer.companyName employer.company_name_missing 05'],
            ExampleDeclaration::warnings($result),
        );
    }

    public function testEveryErrorIsThrownAtOnce(): void
    {
        try {
            Declaration::of(
                ExampleEmployer::with(['postalCode' => '1']),
                ExampleEmployee::with(['birthDepartment' => '']),
                ExampleContract::with(['healthService' => '']),
                ExampleDeclaration::rules(),
                ExampleDeclaration::today(),
            );
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            static::assertSame(
                [
                    'employer.postalCode employer.postal_code_invalid_format 07',
                    'employee.birthDepartment employee.birth_department_empty 47',
                    'contract.healthService contract.health_service_empty 50',
                ],
                ExampleDeclaration::errors($exception->result()),
            );
        }
    }

    public function testACttRequiresTheTemporaryWorkApeCode(): void
    {
        $accepted = Declaration::validate(
            ExampleEmployer::with(['apeCode' => '7820Z']),
            ExampleEmployee::with([]),
            ExampleContract::with(['type' => ContractType::Ctt, 'healthService' => null]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );
        $refused = Declaration::validate(
            ExampleEmployer::with(['apeCode' => '8110Z']),
            ExampleEmployee::with([]),
            ExampleContract::with(['type' => ContractType::Ctt, 'healthService' => null]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );
        $lowerCase = Declaration::validate(
            ExampleEmployer::with(['apeCode' => '7820z']),
            ExampleEmployee::with([]),
            ExampleContract::with(['type' => ContractType::Ctt, 'healthService' => null]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        static::assertSame([], ExampleDeclaration::errors($accepted));
        static::assertSame(['contract.type contract.ctt_requires_naf_7820z 45'], ExampleDeclaration::errors($refused));
        static::assertSame(['contract.type contract.ctt_requires_naf_7820z 45'], ExampleDeclaration::errors($lowerCase));
    }

    public function testOtherContractTypesAcceptAnyApeCode(): void
    {
        foreach ([ContractType::Cdd, ContractType::Cdi] as $type) {
            $result = Declaration::validate(
                ExampleEmployer::with(['apeCode' => '8110Z']),
                ExampleEmployee::with([]),
                ExampleContract::with(['type' => $type]),
                ExampleDeclaration::rules(),
                ExampleDeclaration::today(),
            );

            static::assertSame([], ExampleDeclaration::errors($result));
        }
    }

    public function testDebugInfoShowsNoPersonalData(): void
    {
        static::assertSame(
            ['contractType' => ContractType::Cdi, 'warnings' => 1],
            ExampleDeclaration::declaration()->__debugInfo(),
        );
    }

    public function testItCannotBeSerialized(): void
    {
        $this->expectException(PayloadNotSerializable::class);

        serialize(ExampleDeclaration::declaration());
    }

    public function testItCannotBeUnserialized(): void
    {
        $declaration = ExampleDeclaration::declaration();

        $this->expectException(PayloadNotSerializable::class);

        $declaration->__unserialize([]);
    }
}
