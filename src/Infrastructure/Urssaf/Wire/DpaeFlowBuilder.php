<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafDpae\Domain\Declaration\Contract;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationVocabulary;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employee;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employer;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * Writes a {@see Declaration} as the DPAE XML flow of the official example (ADR 0004, ADR 0007): same element names,
 * order and namespaces, `<?xml version="1.0" encoding="ISO-8859-1" ?>`, the test indicator of the deposit mode.
 *
 * Only the elements of the example are written; the raison sociale has no published element and is not serialized
 * (api-reference.md §5.12). A value that ISO-8859-1 cannot hold, or an XML-invalid control character, is a validation
 * error (`declaration.value_not_encodable`), never a silent substitution. The result is a {@see DpaeFlow}, so it
 * passes the same mode check as a raw flow.
 */
final readonly class DpaeFlowBuilder
{
    private const DECLARATION = '<?xml version="1.0" encoding="ISO-8859-1" ?>';
    private const ROOT_OPEN = '<FR_DUE_Upload'
        . ' xmlns:cct="urn:oasis:names:tc:ubl:corecomponentTypes:1.0:0.70"'
        . ' xmlns:rxdt="http://www.repxml.org/DataTypes"'
        . ' xmlns:rxorg="http://www.repxml.org/Organization"'
        . ' xmlns:rxpadr="http://www.repxml.org/PostalAddress"'
        . ' xmlns:rxpers="http://www.repxml.org/Person_Identity"'
        . ' xmlns:rxphadr="http://www.repxml.org/PhoneAddress"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';

    /** Everything outside U+0020..U+007E and U+00A0..U+00FF: not ISO-8859-1, or a C0 / DEL / C1 control character. */
    private const NOT_ENCODABLE_PATTERN = '/[^\x{20}-\x{7E}\x{A0}-\x{FF}]/u';

    public function __construct(
        private TestIndicators $indicators,
        private DeclarationVocabulary $vocabulary,
    ) {}

    /**
     * @throws ValidationFailed `declaration.value_not_encodable`, one violation per offending value
     */
    public function build(Declaration $declaration, DeclarationMode $mode): DpaeFlow
    {
        $employer = $declaration->employer();
        $employee = $declaration->employee();
        $contract = $declaration->contract();
        $indicator = $this->indicators->forMode($mode);
        $contractType = $this->vocabulary->contractType($contract->type());

        $this->assertEncodable([
            'testIndicator' => $indicator,
            'employer.siret' => $employer->siret()->value(),
            'employer.apeCode' => $employer->apeCode(),
            'employer.urssafCode' => $employer->urssafCode(),
            'employer.street' => $employer->street(),
            'employer.town' => $employer->town(),
            'employer.postalCode' => $employer->postalCode(),
            'employer.phone' => $employer->phone(),
            'employee.surname' => $employee->surname(),
            'employee.firstName' => $employee->firstName(),
            'employee.sexCode' => $employee->sexCode(),
            'employee.nir' => $employee->nir(),
            'employee.birthTown' => $employee->birthTown(),
            'employee.birthDepartment' => $employee->birthDepartment(),
            'contract.type' => $contractType,
            'contract.healthService' => $contract->healthService(),
        ]);

        $xml = self::DECLARATION . "\n" . self::ROOT_OPEN
            . self::text('FR_DUE_Upload.Test.Indicator', $indicator)
            . '<FR_DuesGroup>' . self::employerXml($employer)
            . '<FR_EmployeeGroup>' . self::employeeXml($employee)
            . self::contractXml($contract, $contractType) . '</FR_EmployeeGroup>'
            . '</FR_DuesGroup></FR_DUE_Upload>';

        // Every value was checked above: UTF-8, nothing above U+00FF, so the conversion cannot fail.
        /** @var string $bytes */
        $bytes = mb_convert_encoding($xml, 'ISO-8859-1', 'UTF-8');

        return DpaeFlow::fromXml($bytes, $mode, $this->indicators);
    }

    private static function employerXml(Employer $employer): string
    {
        return '<FR_Employer><FR_EmployerIdentity>'
            . self::text('rxorg:FR_Organization.SIRET.Identifier', $employer->siret()->value())
            . self::text('rxorg:FR_Organization.APE.Code', $employer->apeCode())
            . '</FR_EmployerIdentity>'
            . self::text('FR_Employer.URSSAF.Code', $employer->urssafCode())
            . '<FR_EmployerAddress>'
            . self::text('rxpadr:FR_PostalAddress.StreetDesignation.Text', $employer->street())
            . self::text('rxpadr:FR_PostalAddress.Town.Text', $employer->town())
            . self::text('rxpadr:FR_PostalAddress.Postal.Code', $employer->postalCode())
            . '</FR_EmployerAddress>'
            . self::phoneXml($employer->phone())
            . '</FR_Employer>';
    }

    private static function phoneXml(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        return '<FR_EmployerContact><FR_PhoneNumber>'
            . self::text('rxphadr:FR_PhoneAddress.PhoneNumber.Text', $phone)
            . '</FR_PhoneNumber></FR_EmployerContact>';
    }

    private static function employeeXml(Employee $employee): string
    {
        return '<FR_Employee><FR_EmployeeIdentity>'
            . self::text('rxpers:FR_PersonIdentity.Surname.Text', $employee->surname())
            . self::text('rxpers:FR_PersonIdentity.ChristianName.Text', $employee->firstName())
            . self::optionalText('rxpers:FR_PersonIdentity.Sex.Code', $employee->sexCode())
            . self::nirXml($employee)
            . '<rxpers:FR_Birth>'
            . self::text('rxpers:FR_Birth.Date', $employee->birthDate()->format('Y-m-d'))
            . self::text('rxpers:FR_Birth.Town.Text', $employee->birthTown())
            . '</rxpers:FR_Birth>'
            . '</FR_EmployeeIdentity>'
            . '<FR_EmployeeComplement>'
            . self::text('FR_EmployeeComplement.Birth_Department.Code', $employee->birthDepartment())
            . '</FR_EmployeeComplement>'
            . '</FR_Employee>';
    }

    private static function nirXml(Employee $employee): string
    {
        $identifier = $employee->nirIdentifier();
        if ($identifier === null) {
            return '';
        }

        return '<rxpers:FR_NNI>'
            . self::text('rxpers:FR_NNI.NIR.Identifier', $identifier)
            . self::optionalText('rxpers:FR_NNI.NIRKey.Text', $employee->nirKey())
            . '</rxpers:FR_NNI>';
    }

    private static function contractXml(Contract $contract, string $contractType): string
    {
        $endDate = $contract->endDate();

        return '<FR_Contract>'
            . self::text('FR_Contract.StartContract.Date', $contract->start()->format('Y-m-d'))
            . self::text('FR_Contract.StartContract.Time', $contract->start()->format('H:i:s'))
            . self::optionalText('FR_Contract.EndContract.Date', $endDate?->format('Y-m-d'))
            . self::text('FR_Contract.Nature.Code', $contractType)
            . self::optionalText('FR_Contract.HealthService.Text', $contract->healthService())
            . '</FR_Contract>';
    }

    private static function optionalText(string $element, ?string $value): string
    {
        return $value === null ? '' : self::text($element, $value);
    }

    private static function text(string $element, string $value): string
    {
        return '<' . $element . '>' . strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']) . '</' . $element . '>';
    }

    /**
     * @param array<string, string|null> $values
     */
    private function assertEncodable(array $values): void
    {
        $violations = [];
        foreach ($values as $path => $value) {
            if ($value === null || preg_match(self::NOT_ENCODABLE_PATTERN, $value) === 0) {
                continue;
            }
            $violations[] = Violation::error(
                $path,
                'declaration.value_not_encodable',
                'The value cannot be written in ISO-8859-1 (printable characters U+0020 to U+007E and U+00A0 to U+00FF only).',
            );
        }
        if ($violations !== []) {
            throw ValidationFailed::withResult(ValidationResult::of(...$violations));
        }
    }
}
