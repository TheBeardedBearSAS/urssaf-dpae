<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository;

use Doctrine\DBAL\Connection;

/**
 * Insert or update of one row by its key, for the tables whose rows have no version: the row exists or it does not, and
 * the statement that follows says so (no driver-specific "affected rows" semantics, no `ON CONFLICT` syntax).
 */
final readonly class Upsert
{
    public function __construct(
        private Connection $connection,
        private string $table,
        private string $key,
    ) {}

    /**
     * @param string                $keyValue the scalar value of the key column in `$row`
     * @param array<string, mixed>  $row      the columns, the key included
     * @param array<string, string> $types    DBAL type of the columns that are not plain strings
     * @param string                $unless   SQL condition on the stored row, empty for none: when it holds, the existing row is left as it is
     */
    public function write(string $keyValue, array $row, array $types, string $unless = ''): void
    {
        $exists = $this->connection->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s = ?', $this->table, $this->key), [$keyValue]) !== false;

        if ($exists && $unless !== '') {
            $assignments = implode(', ', array_map(static fn(string $column): string => \sprintf('%1$s = :%1$s', $column), array_keys($row)));
            $this->connection->executeStatement(
                \sprintf('UPDATE %s SET %s WHERE %s = :%s AND NOT (%s)', $this->table, $assignments, $this->key, $this->key, $unless),
                $row,
                $types,
            );

            return;
        }
        if ($exists) {
            $this->connection->update($this->table, $row, [$this->key => $row[$this->key]], $types);

            return;
        }
        $this->connection->insert($this->table, $row, $types);
    }
}
