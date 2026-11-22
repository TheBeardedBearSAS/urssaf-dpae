<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Type;

use DateTimeImmutable;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\BytesType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;

final class InstantAndBytesTypesTest extends TestCase
{
    private SQLitePlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new SQLitePlatform();
    }

    public function testAnInstantIsStoredInUtcWhateverItsOffset(): void
    {
        $type = new InstantType();
        $instant = Instant::fromDateTime(new DateTimeImmutable('2022-02-01T12:00:00+02:00'));

        static::assertSame('2022-02-01 10:00:00', $type->convertToDatabaseValue($instant, $this->platform));
        static::assertTrue($instant->equals($type->convertToPHPValue('2022-02-01 10:00:00', $this->platform) ?? $instant));
        static::assertSame('2022-02-01T10:00:00Z', $type->convertToPHPValue('2022-02-01 10:00:00', $this->platform)?->toIso8601());
        static::assertSame('DATETIME', $type->getSQLDeclaration([], $this->platform));
    }

    public function testAnInstantTypeKeepsNullAndRefusesTheRest(): void
    {
        $type = new InstantType();

        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
        $written = Outcome::of(fn(): ?string => $type->convertToDatabaseValue('2022-02-01', $this->platform));
        $read = Outcome::of(fn(): ?Instant => $type->convertToPHPValue(20_220_201, $this->platform));
        $malformed = Outcome::of(fn(): ?Instant => $type->convertToPHPValue('yesterday', $this->platform));

        static::assertInstanceOf(InvalidType::class, $written);
        static::assertSame("Could not convert PHP value '2022-02-01' to type " . InstantType::class . '. Expected one of the following types: ' . Instant::class . ', null.', $written->getMessage());
        static::assertInstanceOf(InvalidType::class, $read);
        static::assertSame('Could not convert PHP value 20220201 to type ' . InstantType::class . '. Expected one of the following types: string, null.', $read->getMessage());
        static::assertInstanceOf(InvalidFormat::class, $malformed);
    }

    public function testBytesAreStoredInBase64AndReadBack(): void
    {
        $type = new BytesType();
        $bytes = "\0\xFF binary \xE9";

        static::assertSame(base64_encode($bytes), $type->convertToDatabaseValue($bytes, $this->platform));
        static::assertSame($bytes, $type->convertToPHPValue(base64_encode($bytes), $this->platform));
        static::assertSame('', $type->convertToPHPValue('', $this->platform));
        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
        static::assertSame('CLOB', $type->getSQLDeclaration([], $this->platform));
    }

    public function testBytesRefuseAnythingElse(): void
    {
        $type = new BytesType();
        $written = Outcome::of(fn(): ?string => $type->convertToDatabaseValue(12, $this->platform));
        $read = Outcome::of(fn(): ?string => $type->convertToPHPValue(12, $this->platform));
        $malformed = Outcome::of(fn(): ?string => $type->convertToPHPValue('***', $this->platform));

        static::assertInstanceOf(InvalidType::class, $written);
        static::assertSame('Could not convert PHP value 12 to type ' . BytesType::class . '. Expected one of the following types: string, null.', $written->getMessage());
        static::assertInstanceOf(InvalidType::class, $read);
        static::assertSame('Could not convert PHP value 12 to type ' . BytesType::class . '. Expected one of the following types: string, null.', $read->getMessage());
        static::assertInstanceOf(InvalidFormat::class, $malformed);
        static::assertSame('Could not convert database value "***" to Doctrine Type ' . BytesType::class . '. Expected format "base 64".', $malformed->getMessage());
    }
}
