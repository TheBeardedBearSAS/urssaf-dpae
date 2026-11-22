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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmployerSiretTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideSirets(): iterable
    {
        yield 'fourteen digits' => ['99999999999999', []];
        yield 'fourteen characters with a letter' => ['9999999999999A', ['siret employer.siret_invalid_format ']];
        yield 'fourteen characters with a space' => ['9999999999 999', ['siret employer.siret_invalid_format ']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('provideSirets')]
    public function testSiretMustBeFourteenDigits(string $siret, array $expected): void
    {
        static::assertSame($expected, ExampleDeclaration::errors(ExampleEmployer::with(['siret' => $siret])->validate()));
    }
}
