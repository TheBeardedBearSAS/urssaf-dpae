<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declarant;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * SIRET as published by the Swagger: exactly 14 characters (api-reference.md §2.1). Nothing else is checked locally
 * (no digit-only rule, no Luhn key): URSSAF applies its own controls.
 */
final readonly class Siret
{
    private const LENGTH = 14;

    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') !== self::LENGTH) {
            throw InvalidValue::invalidFormat('siret', self::LENGTH . ' characters');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
