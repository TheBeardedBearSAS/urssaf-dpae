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

use DateTimeImmutable;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * One hire: one employer, one employee, one contract (one flow, one rank; ADR 0007). A `Declaration` that exists passed
 * the local §3.4 checks; the warnings of the validation stay available ({@see self::warnings()}).
 *
 * Paths of the violations are prefixed with `employer`, `employee` and `contract`. `$today` is the reference date of
 * the rules "before today" (birth) and "within one year" (hire), supplied by the caller (no clock in the Domain).
 */
final readonly class Declaration
{
    use ForbidsSerialization;

    private const CTT_REQUIRED_APE_CODE = '7820Z';

    /**
     * @param list<Violation> $warnings
     */
    private function __construct(
        private Employer $employer,
        private Employee $employee,
        private Contract $contract,
        private array $warnings,
    ) {}

    /**
     * Every violation and warning, without throwing.
     */
    public static function validate(
        Employer $employer,
        Employee $employee,
        Contract $contract,
        DeclarationRules $rules,
        DateTimeImmutable $today,
    ): ValidationResult {
        $result = $employer
            ->validate()
            ->withPathPrefix('employer')
            ->merge($employee->validate($rules, $today)->withPathPrefix('employee'))
            ->merge($contract->validate($today)->withPathPrefix('contract'));
        if ($contract->type() === ContractType::Ctt && $employer->apeCode() !== self::CTT_REQUIRED_APE_CODE) {
            $result = $result->with(Violation::error(
                'contract.type',
                'contract.ctt_requires_naf_7820z',
                'A CTT requires the employer APE code 7820Z.',
                ['normCode' => '45'],
            ));
        }

        return $result;
    }

    /**
     * @throws ValidationFailed with every error found
     */
    public static function of(
        Employer $employer,
        Employee $employee,
        Contract $contract,
        DeclarationRules $rules,
        DateTimeImmutable $today,
    ): self {
        $result = self::validate($employer, $employee, $contract, $rules, $today);
        $result->throwIfInvalid();

        return new self($employer, $employee, $contract, $result->warnings());
    }

    public function employer(): Employer
    {
        return $this->employer;
    }

    public function employee(): Employee
    {
        return $this->employee;
    }

    public function contract(): Contract
    {
        return $this->contract;
    }

    /**
     * @return list<Violation>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['contractType' => $this->contract->type(), 'warnings' => \count($this->warnings)];
    }
}
