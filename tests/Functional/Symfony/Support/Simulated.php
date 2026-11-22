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

/**
 * Configuration of an application whose URSSAF is the in-memory simulator.
 */
final class Simulated
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public static function config(array $config): array
    {
        // @mago-expect lint:inline-variable-return The variable carries the key type that array_replace_recursive() loses.
        // @mago-expect analysis:redundant-docblock-type PHPStan loses the key type of array_replace_recursive(), which Mago keeps.
        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive(['gateway' => ['simulated' => true]], $config);

        return $merged;
    }
}
