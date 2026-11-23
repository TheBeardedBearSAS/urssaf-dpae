<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use DateTimeImmutable;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationRules;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * The declaration of the official example (api-reference.md §2.2) and helpers to read validation results.
 */
final class ExampleDeclaration
{
    /** The reference day of the official example: its hire date. */
    public static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('2015-07-17 12:30:00');
    }

    /** A reference day for the rules tests. */
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-22 08:00:00');
    }

    /** The permissive name rules the official example needs: its first name `Alain` is mixed case (§5.12). */
    public static function rules(): DeclarationRules
    {
        return DeclarationRules::of("/^[A-Za-z' .&-]+\$/D");
    }

    public static function declaration(): Declaration
    {
        return Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with([]),
            ExampleContract::with([]),
            self::rules(),
            self::today(),
        );
    }

    /**
     * Each error as "path code normCode".
     *
     * @return list<string>
     */
    public static function errors(ValidationResult $result): array
    {
        return array_map(self::describe(...), $result->errors());
    }

    /**
     * Each warning as "path code normCode".
     *
     * @return list<string>
     */
    public static function warnings(ValidationResult $result): array
    {
        return array_map(self::describe(...), $result->warnings());
    }

    public static function describe(Violation $violation): string
    {
        $normCode = $violation->params()['normCode'] ?? '';

        return $violation->path() . ' ' . $violation->code() . ' ' . (\is_string($normCode) ? $normCode : '');
    }
}
