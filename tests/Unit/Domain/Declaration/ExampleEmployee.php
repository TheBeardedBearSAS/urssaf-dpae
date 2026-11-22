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

use DateTimeImmutable;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employee;

/**
 * The employee of the official example, each field overridable.
 */
final class ExampleEmployee
{
    /**
     * @param array{surname?: string, firstName?: string, sexCode?: string|null, nir?: string|null, birthDate?: DateTimeImmutable, birthTown?: string, birthDepartment?: string} $with
     */
    public static function with(array $with): Employee
    {
        return Employee::of(
            $with['surname'] ?? 'TEST',
            $with['firstName'] ?? 'Alain',
            \array_key_exists('sexCode', $with) ? $with['sexCode'] : '1',
            \array_key_exists('nir', $with) ? $with['nir'] : '100000000000099',
            $with['birthDate'] ?? new DateTimeImmutable('1970-01-01'),
            $with['birthTown'] ?? 'PARIS',
            $with['birthDepartment'] ?? '75',
        );
    }
}
