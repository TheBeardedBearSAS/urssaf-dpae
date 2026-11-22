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

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;

/**
 * Reads the tables of the test database.
 */
final class Rows
{
    private function __construct() {}

    /**
     * @param non-empty-string $table
     */
    public static function count(Connection $connection, string $table): int
    {
        $count = $connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
        Assert::assertIsNumeric($count);

        return (int) $count;
    }
}
