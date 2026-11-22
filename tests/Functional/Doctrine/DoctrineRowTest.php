<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine;

use Doctrine\DBAL\Types\Exception\InvalidFormat;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\Row;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use UnexpectedValueException;

final class DoctrineRowTest extends TestCase
{
    private function row(): Row
    {
        return new Row(TestDatabase::entityManager()->getConnection(), [
            'text' => 'abc',
            'number' => '5',
            'flag' => 1,
            'nothing' => null,
            'tenant' => 't1',
            'bytes' => base64_encode('raw'),
            'integer_as_text' => 5,
        ]);
    }

    public function testItReadsTheColumnsThroughTheirTypes(): void
    {
        $row = $this->row();

        static::assertSame('abc', $row->string('text'));
        static::assertSame('abc', $row->nullableString('text'));
        static::assertNull($row->nullableString('nothing'));
        static::assertNull($row->nullableString('missing'));
        static::assertSame(5, $row->int('number'));
        static::assertTrue($row->bool('flag'));
        static::assertSame('raw', $row->bytes('bytes'));
        static::assertEquals(TenantId::fromString('t1'), $row->object('tenant', TenantIdType::NAME, TenantId::class));
        static::assertEquals(TenantId::fromString('t1'), $row->nullableObject('tenant', TenantIdType::NAME, TenantId::class));
        static::assertNull($row->nullableObject('nothing', TenantIdType::NAME, TenantId::class));
    }

    public function testItRefusesAColumnThatDoesNotHoldWhatIsExpected(): void
    {
        $row = $this->row();

        foreach ([
            static fn(): string => $row->string('nothing'),
            static fn(): string => $row->string('integer_as_text'),
            static fn(): ?string => $row->nullableString('integer_as_text'),
            static fn(): int => $row->int('nothing'),
            static fn(): bool => $row->bool('nothing'),
            static fn(): string => $row->bytes('nothing'),
            static fn(): object => $row->object('nothing', TenantIdType::NAME, TenantId::class),
            static fn(): object => $row->object('tenant', TenantIdType::NAME, FlowId::class),
            static fn(): ?object => $row->nullableObject('tenant', TenantIdType::NAME, FlowId::class),
        ] as $read) {
            $failure = Outcome::of($read);
            static::assertNotNull($failure);
            static::assertTrue($failure instanceof UnexpectedValueException || $failure instanceof InvalidFormat);
        }
        static::assertSame('The column "nothing" does not hold a string.', Outcome::of(static fn(): string => $row->string('nothing'))?->getMessage());
        static::assertSame('The column "nothing" does not hold an integer.', Outcome::of(static fn(): int => $row->int('nothing'))?->getMessage());
        static::assertSame('The column "nothing" does not hold a boolean.', Outcome::of(static fn(): bool => $row->bool('nothing'))?->getMessage());
        static::assertSame('The column "nothing" does not hold bytes.', Outcome::of(static fn(): string => $row->bytes('nothing'))?->getMessage());
        static::assertSame('The column "tenant" does not hold ' . FlowId::class . '.', Outcome::of(static fn(): object => $row->object('tenant', TenantIdType::NAME, FlowId::class))?->getMessage());
    }
}
