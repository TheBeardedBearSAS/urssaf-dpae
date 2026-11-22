<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Tenant;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

final class TenantIdTest extends TestCase
{
    public function testItKeepsTheValueVerbatim(): void
    {
        $id = TenantId::fromString(' acme ');

        static::assertSame(' acme ', $id->value());
        static::assertSame(' acme ', (string) $id);
    }

    public function testItAcceptsOneAndSixtyFourCharacters(): void
    {
        static::assertSame('a', TenantId::fromString('a')->value());
        static::assertSame(64, mb_strlen(TenantId::fromString(str_repeat('é', 64))->value()));
    }

    public function testItRejectsMoreThanSixtyFourCharacters(): void
    {
        $this->assertViolation(str_repeat('é', 65), 'tenant_id.too_long');
    }

    public function testItRejectsBlankValues(): void
    {
        $this->assertViolation('', 'tenant_id.empty');
        $this->assertViolation(" \t\n", 'tenant_id.empty');
    }

    public function testItRejectsInvalidUtf8(): void
    {
        $this->assertViolation("\xFF", 'tenant_id.invalid_format');
    }

    public function testItRejectsControlCharacters(): void
    {
        foreach (["a\x00b", "a\nb", "a\rb", "a\tb", "a\x1Fb", "a\x7Fb"] as $value) {
            $this->assertViolation($value, 'tenant_id.invalid_format');
        }
        static::assertSame("a\u{80}b", TenantId::fromString("a\u{80}b")->value());
        static::assertSame('a b ~', TenantId::fromString('a b ~')->value());
    }

    public function testItIsComparedByValue(): void
    {
        static::assertTrue(TenantId::fromString('a')->equals(TenantId::fromString('a')));
        static::assertFalse(TenantId::fromString('a')->equals(TenantId::fromString('b')));
    }

    private function assertViolation(string $value, string $code): void
    {
        try {
            TenantId::fromString($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }
}
