<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * Local checks shared by the model classes (api-reference.md §3.4). A violation carries the norm code it mirrors in
 * its `normCode` parameter; messages never echo the checked value (personal data).
 *
 * @internal
 */
final readonly class FieldCheck
{
    /** Allowed characters of the employer texts (raison sociale, address, commune), §3.4. */
    public const EMPLOYER_TEXT_PATTERN = "/^[a-zA-Z0-9éèêë âàä öô ûü îï ç°² !#\$%&'()*+,\\-.\\/:;<=>?@]+\$/uD";

    /** Allowed characters of the birth place, §3.4. */
    public const BIRTH_TOWN_PATTERN = "/^[A-Z0-9 .'-]+\$/D";

    public const TEXT_MAX_LENGTH = 32;

    private function __construct(private string $subject) {}

    public static function for(string $subject): self
    {
        return new self($subject);
    }

    /**
     * Mandatory text, at most {@see self::TEXT_MAX_LENGTH} characters, made of the characters of the pattern. Codes
     * `<subject>.<field>_empty`, `_too_long` and `_invalid_characters`.
     *
     * @return list<Violation>
     */
    public function text(
        string $path,
        string $field,
        string $value,
        string $pattern,
        string $normCode,
    ): array {
        if (trim($value) === '') {
            return [Violation::error(
                $path,
                \sprintf('%s.%s_empty', $this->subject, $field),
                \sprintf('The %s is mandatory.', $path),
                ['normCode' => $normCode],
            )];
        }

        $violations = [];
        if (mb_strlen($value, 'UTF-8') > self::TEXT_MAX_LENGTH) {
            $violations[] = Violation::error(
                $path,
                \sprintf('%s.%s_too_long', $this->subject, $field),
                \sprintf('The %s must not exceed %d characters.', $path, self::TEXT_MAX_LENGTH),
                ['normCode' => $normCode, 'max_length' => self::TEXT_MAX_LENGTH],
            );
        }
        if (preg_match($pattern, $value) !== 1) {
            $violations[] = Violation::error(
                $path,
                \sprintf('%s.%s_invalid_characters', $this->subject, $field),
                \sprintf('The %s contains a character that the norm does not allow.', $path),
                ['normCode' => $normCode],
            );
        }

        return $violations;
    }

    /**
     * Mandatory, non-blank value without any further rule. Code `<subject>.<field>_empty`.
     *
     * @return list<Violation>
     */
    public function mandatory(
        string $path,
        string $field,
        string $value,
        string $normCode,
    ): array {
        if (trim($value) !== '') {
            return [];
        }

        return [Violation::error(
            $path,
            \sprintf('%s.%s_empty', $this->subject, $field),
            \sprintf('The %s is mandatory.', $path),
            ['normCode' => $normCode],
        )];
    }
}
