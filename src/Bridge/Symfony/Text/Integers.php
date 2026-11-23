<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Text;

/**
 * Reads an integer written as text: an optional minus sign and decimal digits without leading zero, nothing else (no
 * space, no sign "+", no fraction). A value beyond the PHP integer range is clamped by PHP, which the callers then refuse
 * as out of range.
 */
final class Integers
{
    public static function parse(string $text): ?int
    {
        return preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $text) === 1 ? (int) $text : null;
    }
}
