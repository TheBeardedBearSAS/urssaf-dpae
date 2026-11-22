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
 * Net-Entreprises service code (`service`, int32). The raw integer is kept and sent as is; `kind()` tells whether it is
 * one of the two documented values.
 */
final readonly class ServiceCode
{
    private const MIN = -2_147_483_648;
    private const MAX = 2_147_483_647;

    private function __construct(private int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw InvalidValue::because(
                'service_code.out_of_range',
                'Invalid service_code: the value must fit in a signed 32-bit integer.',
                ['min' => self::MIN, 'max' => self::MAX],
            );
        }
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public static function direct(): self
    {
        return new self(ServiceKind::Direct->value);
    }

    public static function concentrator(): self
    {
        return new self(ServiceKind::Concentrator->value);
    }

    public function value(): int
    {
        return $this->value;
    }

    public function kind(): ?ServiceKind
    {
        return ServiceKind::fromCode($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
