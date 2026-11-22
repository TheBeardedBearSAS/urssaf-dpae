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
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class FlowIdTest extends TestCase
{
    public function testItKeepsTheValueVerbatim(): void
    {
        $id = FlowId::fromString('F1spdeAilkR4q3UUlQA7haa');

        static::assertSame('F1spdeAilkR4q3UUlQA7haa', $id->value());
        static::assertSame('F1spdeAilkR4q3UUlQA7haa', (string) $id);
    }

    public function testItAcceptsOneAndFiftyCharacters(): void
    {
        static::assertSame('a', FlowId::fromString('a')->value());
        static::assertSame(50, \strlen(FlowId::fromString(str_repeat('a', 50))->value()));
    }

    public function testItCountsCharactersNotBytes(): void
    {
        static::assertSame(50, mb_strlen(FlowId::fromString(str_repeat('é', 50))->value()));
        $this->assertViolation(str_repeat('é', 51), 'flow_id.too_long');
    }

    public function testItRejectsMoreThanFiftyCharacters(): void
    {
        $this->assertViolation(str_repeat('a', 51), 'flow_id.too_long');
    }

    public function testItRejectsBlankValues(): void
    {
        $this->assertViolation('', 'flow_id.empty');
        $this->assertViolation(' ', 'flow_id.empty');
        $this->assertViolation("\t\n", 'flow_id.empty');
    }

    public function testItRejectsInvalidUtf8EvenAtAnAcceptedLength(): void
    {
        $this->assertViolation("\xFF", 'flow_id.invalid_format');
        $this->assertViolation("\xFF" . str_repeat('a', 49), 'flow_id.invalid_format');
    }

    public function testItIsComparedByValue(): void
    {
        static::assertTrue(FlowId::fromString('abc')->equals(FlowId::fromString('abc')));
        static::assertFalse(FlowId::fromString('abc')->equals(FlowId::fromString('abd')));
    }

    private function assertViolation(string $value, string $code): void
    {
        try {
            FlowId::fromString($value);
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame($code, $e->violationCode());
        }
    }
}
