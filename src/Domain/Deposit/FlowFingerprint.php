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

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * SHA-256 (lower-case hex) of the uncompressed flow bytes as deposited (ADR 0005). A local key only: nothing published
 * echoes it.
 */
final readonly class FlowFingerprint
{
    private function __construct(private string $value) {}

    public static function ofBytes(string $flow): self
    {
        return new self(hash('sha256', $flow));
    }

    public static function fromString(string $hex): self
    {
        if (preg_match('/\A[0-9a-f]{64}\z/', $hex) !== 1) {
            throw InvalidValue::invalidFormat('flow_fingerprint', '64 lower-case hexadecimal characters');
        }

        return new self($hex);
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
