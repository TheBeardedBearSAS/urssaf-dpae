<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;

/**
 * Minimal DPAE flows (test data only) with the indicator of a mode.
 */
final class Flows
{
    private function __construct() {}

    public static function xml(DeclarationMode $mode, string $variant): string
    {
        $indicator = $mode === DeclarationMode::Real ? '120' : 'TST';

        return '<FR_DUE_Upload><FR_DUE_Upload.Test.Indicator>' . $indicator . '</FR_DUE_Upload.Test.Indicator><Variant>' . $variant . '</Variant></FR_DUE_Upload>';
    }
}
