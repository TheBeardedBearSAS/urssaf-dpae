<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * The employer of a DPAE, limited to the elements of the official example plus the raison sociale (ADR 0007).
 *
 * Plain data: nothing is checked on creation (the APE and URSSAF codes are only trimmed), {@see self::validate()}
 * collects every §3.4 violation (notification pattern). The SIRET is 14 characters for {@see Siret}; the employer one
 * must also be made of 14 digits (inferred from the example, §2.2). An invalid optional phone number is a warning, not
 * an error: the norm code `99` is a generic one and the element is optional. The raison sociale 1 is mandatory under §3.4 (code `05`) but has no published XML element, so
 * it is optional here, never written, and always reported as a warning (api-reference.md §5.12).
 */
final readonly class Employer
{
    use ForbidsSerialization;

    private const APE_LENGTHS = [4, 5];
    private const URSSAF_CODE_MAX_LENGTH = 3;
    private const POSTAL_CODE_PATTERN = '/^\d{5}$/D';
    private const SIRET_PATTERN = '/^\d{14}$/D';
    private const PHONE_PATTERN = '/^\d{10}$/D';

    private function __construct(
        private Siret $siret,
        private string $apeCode,
        private string $urssafCode,
        private string $street,
        private string $town,
        private string $postalCode,
        private ?string $phone,
        private ?string $companyName,
    ) {}

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per field of the employer: the constructor limit (12) applies to this named constructor, which only forwards them
     */
    public static function of(
        Siret $siret,
        string $apeCode,
        string $urssafCode,
        string $street,
        string $town,
        string $postalCode,
        ?string $phone,
        ?string $companyName,
    ): self {
        return new self($siret, trim($apeCode), trim($urssafCode), $street, $town, $postalCode, $phone, $companyName);
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function apeCode(): string
    {
        return $this->apeCode;
    }

    public function urssafCode(): string
    {
        return $this->urssafCode;
    }

    public function street(): string
    {
        return $this->street;
    }

    public function town(): string
    {
        return $this->town;
    }

    public function postalCode(): string
    {
        return $this->postalCode;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function companyName(): ?string
    {
        return $this->companyName;
    }

    /**
     * Paths are relative to the employer; norm codes of api-reference.md §3.4.
     */
    public function validate(): ValidationResult
    {
        return ValidationResult::of(...[
            ...$this->validateCodes(),
            ...$this->validateAddress(),
            ...$this->validatePhone(),
            ...$this->validateCompanyName(),
        ]);
    }

    /**
     * @return list<Violation>
     */
    private function validateCodes(): array
    {
        $check = FieldCheck::for('employer');
        $violations = [];
        if (preg_match(self::SIRET_PATTERN, $this->siret->value()) !== 1) {
            $violations[] = Violation::error(
                'siret',
                'employer.siret_invalid_format',
                'The employer SIRET must be made of 14 digits.',
            );
        }
        $ape = $check->mandatory('apeCode', 'ape_code', $this->apeCode, '03');
        if ($ape === [] && !\in_array(mb_strlen($this->apeCode, 'UTF-8'), self::APE_LENGTHS, true)) {
            $ape = [Violation::error(
                'apeCode',
                'employer.ape_code_invalid_length',
                'The APE code must have 4 or 5 characters.',
                ['normCode' => '03'],
            )];
        }
        $urssaf = $check->mandatory('urssafCode', 'urssaf_code', $this->urssafCode, '02');
        if ($urssaf === [] && mb_strlen($this->urssafCode, 'UTF-8') > self::URSSAF_CODE_MAX_LENGTH) {
            $urssaf = [Violation::error(
                'urssafCode',
                'employer.urssaf_code_invalid_length',
                'The URSSAF code must have 1 to 3 characters.',
                ['normCode' => '02'],
            )];
        }

        return [...$violations, ...$ape, ...$urssaf];
    }

    /**
     * @return list<Violation>
     */
    private function validateAddress(): array
    {
        $check = FieldCheck::for('employer');
        $violations = [
            ...$check->text('street', 'street', $this->street, FieldCheck::EMPLOYER_TEXT_PATTERN, '06'),
            ...$check->text('town', 'town', $this->town, FieldCheck::EMPLOYER_TEXT_PATTERN, '08'),
        ];
        $postalCode = $this->postalCodeViolation();
        if ($postalCode instanceof Violation) {
            $violations[] = $postalCode;
        }

        return $violations;
    }

    private function postalCodeViolation(): ?Violation
    {
        if (preg_match(self::POSTAL_CODE_PATTERN, $this->postalCode) !== 1) {
            return Violation::error(
                'postalCode',
                'employer.postal_code_invalid_format',
                'The postal code must be made of 5 digits.',
                ['normCode' => '07'],
            );
        }
        if ($this->postalCode === '00000') {
            return Violation::error(
                'postalCode',
                'employer.postal_code_unknown',
                'The postal code 00000 does not exist.',
                ['normCode' => '07'],
            );
        }

        return null;
    }

    /**
     * @return list<Violation>
     */
    private function validatePhone(): array
    {
        if ($this->phone === null || preg_match(self::PHONE_PATTERN, $this->phone) === 1) {
            return [];
        }

        return [Violation::warning(
            'phone',
            'employer.phone_invalid_format',
            'The optional phone number should be made of 10 digits.',
            ['normCode' => '99'],
        )];
    }

    /**
     * @return list<Violation>
     */
    private function validateCompanyName(): array
    {
        if ($this->companyName === null) {
            return [Violation::warning(
                'companyName',
                'employer.company_name_missing',
                'The raison sociale is mandatory under the norm controls but the flow cannot carry it; whether URSSAF accepts a flow without it is an open point.',
                ['normCode' => '05'],
            )];
        }

        return [
            ...FieldCheck::for('employer')->text(
                'companyName',
                'company_name',
                $this->companyName,
                FieldCheck::EMPLOYER_TEXT_PATTERN,
                '05',
            ),
            Violation::warning(
                'companyName',
                'employer.company_name_not_serialized',
                'The raison sociale is validated but not written: its XML element is not published.',
                ['normCode' => '05'],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['siret' => $this->siret->value()];
    }
}
