<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit;

use Stringable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * The host's own identifier of the hire a deposit declares (ADR 0005): the idempotence key of `DepositDpae`, together
 * with the tenant. Opaque to this package.
 *
 * It must carry **no personal data**: no NIR, no name, no birth date, no e-mail. Use an internal identifier (a primary
 * key, a UUID). The reference is stored with the deposit, copied into the integration events relayed by the outbox,
 * shown in manual review reports and written to logs, none of which is protected like the flow itself (ADR 0008).
 */
final readonly class ExternalReference implements Stringable
{
    public const MAX_LENGTH = 100;
    private const SUBJECT = 'external_reference';

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
