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
 * Identifier of a flow set by the DPAE deposit system (`IdFluxDPAE` of the AEE, `flux.id` of the returns).
 */
final readonly class FlowId implements Stringable
{
    public const MAX_LENGTH = 50;
    private const SUBJECT = 'flow_id';

    private function __construct(private string $value) {}

    /**
     * Rejects blank values, invalid UTF-8 and values longer than {@see self::MAX_LENGTH} characters. The value is kept verbatim.
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
