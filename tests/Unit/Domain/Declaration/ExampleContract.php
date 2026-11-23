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
use TheBeardedBear\UrssafDpae\Domain\Declaration\Contract;
use TheBeardedBear\UrssafDpae\Domain\Declaration\ContractType;

/**
 * The contract of the official example, each field overridable.
 */
final class ExampleContract
{
    /**
     * @param array{start?: DateTimeImmutable, endDate?: DateTimeImmutable|null, type?: ContractType, healthService?: string|null} $with
     */
    public static function with(array $with): Contract
    {
        return Contract::of(
            $with['start'] ?? new DateTimeImmutable('2015-07-17 12:30:00'),
            \array_key_exists('endDate', $with) ? $with['endDate'] : new DateTimeImmutable('2015-09-15'),
            $with['type'] ?? ContractType::Cdi,
            \array_key_exists('healthService', $with) ? $with['healthService'] : '387',
        );
    }
}
