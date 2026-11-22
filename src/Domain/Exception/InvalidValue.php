<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Exception;

/**
 * Thrown when a value object cannot be built from the given input.
 *
 * The "subject" is the snake_case name of the value (e.g. "flow_id", "return_id"); the violation code is
 * "<subject>.<reason>" (e.g. "flow_id.too_long").
 */
final class InvalidValue extends DomainException
{
    /**
     * @param array<string, scalar> $params
     */
    public static function because(string $violationCode, string $message, array $params = []): self
    {
        return new self($message, $violationCode, $params);
    }

    public static function empty(string $subject): self
    {
        return new self(\sprintf('Invalid %s: the value must not be empty.', $subject), $subject . '.empty', []);
    }

    public static function tooLong(string $subject, int $maxLength): self
    {
        return new self(
            \sprintf('Invalid %s: the value must not exceed %d characters.', $subject, $maxLength),
            $subject . '.too_long',
            ['max_length' => $maxLength],
        );
    }

    public static function tooShort(string $subject, int $minLength): self
    {
        return new self(
            \sprintf('Invalid %s: the value must have at least %d characters.', $subject, $minLength),
            $subject . '.too_short',
            ['min_length' => $minLength],
        );
    }

    public static function invalidFormat(string $subject, string $expectedFormat): self
    {
        return new self(
            \sprintf('Invalid %s: the value does not match the expected format (%s).', $subject, $expectedFormat),
            $subject . '.invalid_format',
            ['expected_format' => $expectedFormat],
        );
    }

    public static function negative(string $subject): self
    {
        return new self(\sprintf('Invalid %s: the value must not be negative.', $subject), $subject . '.negative', []);
    }

    public static function notPositive(string $subject): self
    {
        return new self(
            \sprintf('Invalid %s: the value must be strictly positive.', $subject),
            $subject . '.not_positive',
            [],
        );
    }

    public static function tooManyDecimals(string $subject, int $maxDecimals): self
    {
        return new self(
            \sprintf('Invalid %s: the value must not have more than %d decimals.', $subject, $maxDecimals),
            $subject . '.too_many_decimals',
            ['max_decimals' => $maxDecimals],
        );
    }

    public static function tooLarge(string $subject): self
    {
        return new self(
            \sprintf('Invalid %s: the value exceeds the supported range.', $subject),
            $subject . '.too_large',
            [],
        );
    }
}
