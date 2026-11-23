<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\Declaration;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleContract;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleDeclaration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployee;
use TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration\ExampleEmployer;

final class DpaeFlowBuilderControlCharactersTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideControlCharacters(): iterable
    {
        yield 'DEL' => ["a\u{7F}b"];
        yield 'first C1 control' => ["a\u{80}b"];
        yield 'NEL' => ["a\u{85}b"];
        yield 'last C1 control' => ["a\u{9F}b"];
        yield 'C0 control' => ["a\u{1F}b"];
    }

    #[DataProvider('provideControlCharacters')]
    public function testControlCharactersAreReported(string $value): void
    {
        $declaration = Declaration::of(
            ExampleEmployer::with([]),
            ExampleEmployee::with([]),
            ExampleContract::with(['healthService' => $value]),
            ExampleDeclaration::rules(),
            ExampleDeclaration::today(),
        );

        try {
            ExampleBuilder::defaults()->build($declaration, DeclarationMode::Real);
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            static::assertSame(['contract.healthService declaration.value_not_encodable '], ExampleDeclaration::errors($exception->result()));
        }
    }
}
