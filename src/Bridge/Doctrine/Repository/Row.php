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
use Doctrine\DBAL\Types\Types;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\BytesType;
use UnexpectedValueException;

/**
 * One row read with DBAL, its columns converted to PHP values by the DBAL types they were written with. A column that
 * does not hold what its type promises (a schema altered by hand) is refused, never coerced.
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $columns
     */
    public function __construct(
        private Connection $connection,
        private array $columns,
    ) {}

    public function string(string $name): string
    {
        return $this->nullableString($name) ?? throw $this->invalid($name, 'a string');
    }

    public function nullableString(string $name): ?string
    {
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the check below narrows it.
        $value = $this->convert($name, Types::STRING);

        return $value === null || \is_string($value) ? $value : throw $this->invalid($name, 'a string');
    }

    public function bytes(string $name): string
    {
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the check below narrows it.
        $value = $this->convert($name, BytesType::NAME);

        return \is_string($value) ? $value : throw $this->invalid($name, 'bytes');
    }

    public function int(string $name): int
    {
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the check below narrows it.
        $value = $this->convert($name, Types::INTEGER);

        return \is_int($value) ? $value : throw $this->invalid($name, 'an integer');
    }

    public function bool(string $name): bool
    {
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the check below narrows it.
        $value = $this->convert($name, Types::BOOLEAN);

        return \is_bool($value) ? $value : throw $this->invalid($name, 'a boolean');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function object(string $name, string $type, string $class): object
    {
        return $this->nullableObject($name, $type, $class) ?? throw $this->invalid($name, $class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function nullableObject(string $name, string $type, string $class): ?object
    {
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the check below narrows it.
        $value = $this->convert($name, $type);
        if ($value === null) {
            return null;
        }

        return $value instanceof $class ? $value : throw $this->invalid($name, $class);
    }

    private function convert(string $name, string $type): mixed
    {
        return $this->connection->convertToPHPValue($this->columns[$name] ?? null, $type);
    }

    private function invalid(string $name, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(\sprintf('The column "%s" does not hold %s.', $name, $expected));
    }
}
