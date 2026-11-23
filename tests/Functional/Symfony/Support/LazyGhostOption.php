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

use Composer\InstalledVersions;

/**
 * The "enable_lazy_ghost_objects" option of DoctrineBundle 2.x, which DoctrineBundle 3 removed (ghost objects are the only mode).
 */
final class LazyGhostOption
{
    private function __construct() {}

    /**
     * @return array{enable_lazy_ghost_objects?: true}
     */
    public static function options(): array
    {
        $version = InstalledVersions::getVersion('doctrine/doctrine-bundle');

        return $version !== null && version_compare($version, '3.0.0', '<') ? ['enable_lazy_ghost_objects' => true] : [];
    }
}
