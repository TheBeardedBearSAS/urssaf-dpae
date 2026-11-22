<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Tenant;

use Stringable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Identifier of the tenant: one authenticating identity (a direct declarant or a concentrator) that owns credentials,
 * deposits and returns (ADR 0001).
 */
final readonly class TenantId implements Stringable
{
    public const MAX_LENGTH = 64;
    private const SUBJECT = 'tenant_id';

    private function __construct(private string $value) {}

    /**
     * Rejects blank values, invalid UTF-8, control characters (`\x00-\x1F`, `\x7F`) and values longer than {@see self::MAX_LENGTH} characters. The value is kept verbatim.
     *
     * @throws InvalidValue
     */
    public static function fromString(string $value): self
    {
        if (trim($value) === '') {
            throw InvalidValue::empty(self::SUBJECT);
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidValue::invalidFormat(self::SUBJECT, 'UTF-8');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidValue::invalidFormat(self::SUBJECT, 'no control character');
        }
        if (mb_strlen($value, 'UTF-8') > self::MAX_LENGTH) {
            throw InvalidValue::tooLong(self::SUBJECT, self::MAX_LENGTH);
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

    public function __toString(): string
    {
        return $this->value;
    }
}
