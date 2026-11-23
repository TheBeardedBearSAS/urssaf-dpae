<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

// Typed class constants are PHP 8.3 syntax and the package supports PHP 8.2, so the constant part of the
// type-coverage rule cannot be satisfied there. It stays at 100 % wherever it applies; it is only
// disabled on 8.3+ runs where the syntax cannot be used. Same rule as on PHP 8.2 today, where it is inapplicable.
return [
    'parameters' => [
        'type_coverage' => [
            'constant' => PHP_VERSION_ID >= 80300 ? 0 : 100,
        ],
    ],
];
