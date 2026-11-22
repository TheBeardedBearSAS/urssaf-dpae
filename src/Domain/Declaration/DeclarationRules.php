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
 * Tunable local rules of the model (ADR 0007).
 *
 * The default is the strict §3.4 set for employee names, `A-Z' .&-` (upper case only). The official example carries the
 * mixed-case first name `Alain` (api-reference.md §5.12), which the default therefore rejects locally; a host that
 * accepts both cases passes `DeclarationRules::of("/^[A-Z' .&-]+\$/D")`. Letters are never transliterated nor
 * upper-cased by the package.
 */
final readonly class DeclarationRules
{
    public const DEFAULT_EMPLOYEE_NAME_PATTERN = "/^[A-Z' .&-]+\$/D";

    private function __construct(private string $employeeNamePattern) {}

    public static function defaults(): self
    {
        return new self(self::DEFAULT_EMPLOYEE_NAME_PATTERN);
    }

    /**
     * @param string $employeeNamePattern full PCRE pattern, with delimiters, applied to surname and first name
     *
     * @throws InvalidValue `employee_name_pattern.invalid_format`
     */
    public static function of(string $employeeNamePattern): self
    {
        set_error_handler(static fn(): bool => true);
        $valid = preg_match($employeeNamePattern, '') !== false;
        restore_error_handler();
        if (!$valid) {
            throw InvalidValue::invalidFormat('employee_name_pattern', 'PCRE pattern with delimiters');
        }

        return new self($employeeNamePattern);
    }

    public function employeeNamePattern(): string
    {
        return $this->employeeNamePattern;
    }
}
