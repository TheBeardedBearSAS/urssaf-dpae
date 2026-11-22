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

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Employer;

/**
 * The employer of the official example, each field overridable.
 */
final class ExampleEmployer
{
    /**
     * @param array{siret?: string, apeCode?: string, urssafCode?: string, street?: string, town?: string, postalCode?: string, phone?: string|null, companyName?: string|null} $with
     */
    public static function with(array $with): Employer
    {
        return Employer::of(
            Siret::fromString($with['siret'] ?? '99999999999999'),
            $with['apeCode'] ?? '8110Z',
            $with['urssafCode'] ?? '257',
            $with['street'] ?? '44 rue DES TEST',
            $with['town'] ?? 'CAEN',
            $with['postalCode'] ?? '14000',
            \array_key_exists('phone', $with) ? $with['phone'] : '0000000000',
            $with['companyName'] ?? null,
        );
    }
}
