<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declarant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class SiretTest extends TestCase
{
    public function testFourteenCharactersAreAccepted(): void
    {
        $siret = Siret::fromString('99999999999999');

        static::assertSame('99999999999999', $siret->value());
        static::assertTrue($siret->equals(Siret::fromString('99999999999999')));
        static::assertFalse($siret->equals(Siret::fromString('99999999999998')));
    }

    public function testOnlyTheLengthIsChecked(): void
    {
        static::assertSame('ABCDEFGHIJKLMN', Siret::fromString('ABCDEFGHIJKLMN')->value());
        static::assertSame('éééééééééééééé', Siret::fromString('éééééééééééééé')->value());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideWrongValues(): iterable
    {
        yield 'empty' => [''];
        yield 'thirteen' => ['1234567890123'];
        yield 'fifteen' => ['123456789012345'];
        yield 'invalid utf-8 at the exact length' => ["\xFF" . '1234567890123'];
        yield 'invalid utf-8' => ["12345678901234\xFF"];
        yield 'trailing newline' => ["12345678901234\n"];
        yield 'fourteen bytes of seven characters' => ['éééééééé'];
    }

    #[DataProvider('provideWrongValues')]
    public function testOtherLengthsAreRefused(string $value): void
    {
        try {
            Siret::fromString($value);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('siret.invalid_format', $exception->violationCode());
            static::assertSame(['expected_format' => '14 characters'], $exception->params());
        }
    }
}
