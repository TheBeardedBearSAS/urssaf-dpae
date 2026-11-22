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
use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * The employee of a DPAE, limited to the elements of the official example (ADR 0007).
 *
 * Plain data: nothing is checked on creation, {@see self::validate()} collects every §3.4 violation. Names, NIR and
 * birth data are personal: `__debugInfo()` hides them, the object cannot be serialized and its parameters are
 * `#[SensitiveParameter]`. `sexCode` and `birthDepartment` are raw strings (vocabulary not published, §5.12).
 */
final readonly class Employee
{
    use ForbidsSerialization;

    private const NIR_IDENTIFIER_LENGTH = 13;
    private const NIR_LENGTHS = [13, 15];

    private function __construct(
        #[SensitiveParameter]
        private string $surname,
        #[SensitiveParameter]
        private string $firstName,
        #[SensitiveParameter]
        private ?string $sexCode,
        #[SensitiveParameter]
        private ?string $nir,
        private DateTimeImmutable $birthDate,
        #[SensitiveParameter]
        private string $birthTown,
        #[SensitiveParameter]
        private string $birthDepartment,
    ) {}

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per field of the employee: the constructor limit (12) applies to this named constructor, which only forwards them
     */
    public static function of(
        #[SensitiveParameter]
        string $surname,
        #[SensitiveParameter]
        string $firstName,
        #[SensitiveParameter]
        ?string $sexCode,
        #[SensitiveParameter]
        ?string $nir,
        DateTimeImmutable $birthDate,
        #[SensitiveParameter]
        string $birthTown,
        #[SensitiveParameter]
        string $birthDepartment,
    ): self {
        return new self($surname, $firstName, $sexCode, $nir, $birthDate, $birthTown, $birthDepartment);
    }

    public function surname(): string
    {
        return $this->surname;
    }

    public function firstName(): string
    {
        return $this->firstName;
    }

    public function sexCode(): ?string
    {
        return $this->sexCode;
    }

    public function nir(): ?string
    {
        return $this->nir;
    }

    /**
     * The first 13 characters of the NIR, written in `FR_NNI.NIR.Identifier` (split inferred from the example).
     */
    public function nirIdentifier(): ?string
    {
        return $this->nir === null ? null : mb_substr($this->nir, 0, self::NIR_IDENTIFIER_LENGTH, 'UTF-8');
    }

    /**
     * The last 2 characters of a 15-character NIR, written in `FR_NNI.NIRKey.Text`; null for a 13-character NIR.
     */
    public function nirKey(): ?string
    {
        if ($this->nir === null || mb_strlen($this->nir, 'UTF-8') === self::NIR_IDENTIFIER_LENGTH) {
            return null;
        }

        return mb_substr($this->nir, self::NIR_IDENTIFIER_LENGTH, null, 'UTF-8');
    }

    public function birthDate(): DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function birthTown(): string
    {
        return $this->birthTown;
    }

    public function birthDepartment(): string
    {
        return $this->birthDepartment;
    }

    /**
     * Paths are relative to the employee; `$today` is the reference of "before today".
     */
    public function validate(DeclarationRules $rules, DateTimeImmutable $today): ValidationResult
    {
        $namePattern = $rules->employeeNamePattern();
        $check = FieldCheck::for('employee');
        $violations = [
            ...$check->text('surname', 'surname', $this->surname, $namePattern, '31'),
            ...$check->text('firstName', 'first_name', $this->firstName, $namePattern, '32'),
            ...$check->text(
                'birthTown',
                'birth_town',
                $this->birthTown,
                FieldCheck::BIRTH_TOWN_PATTERN,
                '34',
            ),
            ...$check->mandatory(
                'birthDepartment',
                'birth_department',
                $this->birthDepartment,
                '47',
            ),
        ];
        if ($this->birthDate->format('Y-m-d') >= $today->format('Y-m-d')) {
            $violations[] = Violation::error(
                'birthDate',
                'employee.birth_date_not_in_the_past',
                'The birth date must be before today.',
                ['normCode' => '33'],
            );
        }
        if ($this->nir !== null && !\in_array(mb_strlen($this->nir, 'UTF-8'), self::NIR_LENGTHS, true)) {
            $violations[] = Violation::error(
                'nir',
                'employee.nir_invalid_length',
                'The NIR must have 13 or 15 characters.',
                ['normCode' => '38'],
            );
        }

        return ValidationResult::of(...$violations);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['employee' => '***'];
    }
}
