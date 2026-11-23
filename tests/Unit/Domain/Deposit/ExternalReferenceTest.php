<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Deposit;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class ExternalReferenceTest extends TestCase
{
    public function testItKeepsTheValueVerbatim(): void
    {
        $id = ExternalReference::fromString('INVOICE-2022/001');

        static::assertSame('INVOICE-2022/001', $id->value());
        static::assertSame('INVOICE-2022/001', (string) $id);
    }

    public function testItAcceptsOneAndSixtyFourCharacters(): void
    {
        static::assertSame('a', ExternalReference::fromString('a')->value());
        static::assertSame(100, mb_strlen(ExternalReference::fromString(str_repeat('é', 100))->value()));
    }

    public function testItRejectsMoreThanSixtyFourCharacters(): void
    {
        $this->assertViolation(str_repeat('é', 101), 'external_reference.too_long');
    }

    public function testItRejectsBlankValues(): void
    {
        $this->assertViolation('', 'external_reference.empty');
        $this->assertViolation(" \t\n", 'external_reference.empty');
    }

    public function testItRejectsInvalidUtf8AndControlCharacters(): void
    {
        $this->assertViolation("\xFF", 'external_reference.invalid_format');
        $this->assertViolation("a\x00b", 'external_reference.invalid_format');
        $this->assertViolation("a\x7Fb", 'external_reference.invalid_format');
        $this->assertViolation("a\x1Fb", 'external_reference.invalid_format');
    }

    public function testItIsComparedByValue(): void
    {
        static::assertTrue(ExternalReference::fromString('abc')->equals(ExternalReference::fromString('abc')));
        static::assertFalse(ExternalReference::fromString('abc')->equals(ExternalReference::fromString('abd')));
    }

    private function assertViolation(string $value, string $code): void
    {
        try {
            ExternalReference::fromString($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }
}
