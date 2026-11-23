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

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Wire values of the enums of the model for `FR_Contract.Nature.Code` (ADR 0007, api-reference.md §5.12).
 *
 * The XML vocabulary is not published: the example carries `CDI`, the norm controls give `1` / `2` / `3`. The defaults
 * `CDD` / `CDI` / `CTT` are inferred from the single attested value; {@see self::normCodes()} is the numeric form.
 * Other raw values of the model (sex code, URSSAF code, health service) are written as given.
 */
final readonly class DeclarationVocabulary
{
    private function __construct(
        private string $cdd,
        private string $cdi,
        private string $ctt,
    ) {}

    public static function defaults(): self
    {
        return new self('CDD', 'CDI', 'CTT');
    }

    /**
     * The numeric form of the norm controls: `1` (CDD), `2` (CDI), `3` (CTT).
     */
    public static function normCodes(): self
    {
        return new self('1', '2', '3');
    }

    /**
     * @throws InvalidValue `contract_type_cdd.empty`, `contract_type_cdi.empty`, `contract_type_ctt.empty` or
     *                      `declaration_vocabulary.not_distinct`
     */
    public static function of(string $cdd, string $cdi, string $ctt): self
    {
        if (trim($cdd) === '') {
            throw InvalidValue::empty('contract_type_cdd');
        }
        if (trim($cdi) === '') {
            throw InvalidValue::empty('contract_type_cdi');
        }
        if (trim($ctt) === '') {
            throw InvalidValue::empty('contract_type_ctt');
        }
        if ($cdd === $cdi || $cdd === $ctt || $cdi === $ctt) {
            throw InvalidValue::because(
                'declaration_vocabulary.not_distinct',
                'Invalid declaration_vocabulary: the three contract type values must differ.',
            );
        }

        return new self($cdd, $cdi, $ctt);
    }

    public function contractType(ContractType $type): string
    {
        return match ($type) {
            ContractType::Cdd => $this->cdd,
            ContractType::Cdi => $this->cdi,
            ContractType::Ctt => $this->ctt,
        };
    }
}
