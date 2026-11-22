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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class DepositIdTest extends TestCase
{
    public function testItKeepsTheValueVerbatim(): void
    {
        $id = DepositId::fromString('3f2b8c1e-0000-4000-8000-000000000001');

        static::assertSame('3f2b8c1e-0000-4000-8000-000000000001', $id->value());
        static::assertSame('3f2b8c1e-0000-4000-8000-000000000001', (string) $id);
    }

    public function testItAcceptsOneAndSixtyFourCharacters(): void
    {
        static::assertSame('a', DepositId::fromString('a')->value());
        static::assertSame(64, mb_strlen(DepositId::fromString(str_repeat('é', 64))->value()));
    }

    public function testItRejectsMoreThanSixtyFourCharacters(): void
    {
        $this->assertViolation(str_repeat('é', 65), 'deposit_id.too_long');
    }

    public function testItRejectsBlankValues(): void
    {
        $this->assertViolation('', 'deposit_id.empty');
        $this->assertViolation(" \t\n", 'deposit_id.empty');
    }

    public function testItRejectsInvalidUtf8AndControlCharacters(): void
    {
        $this->assertViolation("\xFF", 'deposit_id.invalid_format');
        $this->assertViolation("a\x00b", 'deposit_id.invalid_format');
        $this->assertViolation("a\x7Fb", 'deposit_id.invalid_format');
        $this->assertViolation("a\x1Fb", 'deposit_id.invalid_format');
    }

    public function testItIsComparedByValue(): void
    {
        static::assertTrue(DepositId::fromString('abc')->equals(DepositId::fromString('abc')));
        static::assertFalse(DepositId::fromString('abc')->equals(DepositId::fromString('abd')));
    }

    private function assertViolation(string $value, string $code): void
    {
        try {
            DepositId::fromString($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }
}
