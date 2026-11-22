<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * `idRetour` of the download path `GET /telecharger-retour/1.0/{idFlux}/{idRetour}`: 1-10 characters
 * (api-reference.md §2.5, §4.10). Checked when a download is built, never when a listing is read
 * ({@see ReturnReference::downloadId()}).
 */
final readonly class ReturnId
{
    public const MAX_LENGTH = 10;
    private const SUBJECT = 'return_id';

    private function __construct(private string $value) {}

    /**
     * @throws InvalidValue `return_id.invalid_format` (not UTF-8), `return_id.empty` or `return_id.too_long`
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidValue::invalidFormat(self::SUBJECT, 'UTF-8');
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length === 0) {
            throw InvalidValue::empty(self::SUBJECT);
        }
        if ($length > self::MAX_LENGTH) {
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
}
