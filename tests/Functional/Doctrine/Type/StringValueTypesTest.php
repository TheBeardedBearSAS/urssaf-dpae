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

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\ExternalReferenceType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FingerprintType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FlowIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;

final class StringValueTypesTest extends TestCase
{
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new SQLitePlatform();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function types(): Iterator
    {
        foreach (['tenant id', 'deposit id', 'external reference', 'flow id', 'fingerprint', 'siret'] as $case) {
            yield $case => [$case];
        }
    }

    /**
     * @return array{Type, string, object, object} the type, its registered name, a value and a value of another class
     */
    private static function sample(string $case): array
    {
        return match ($case) {
            'tenant id' => [new TenantIdType(), 'urssaf_dpae_tenant_id', TenantId::fromString('t1'), DepositId::fromString('dep-1')],
            'deposit id' => [new DepositIdType(), 'urssaf_dpae_deposit_id', DepositId::fromString('dep-1'), TenantId::fromString('t1')],
            'external reference' => [new ExternalReferenceType(), 'urssaf_dpae_external_reference', ExternalReference::fromString('ref-1'), TenantId::fromString('t1')],
            'flow id' => [new FlowIdType(), 'urssaf_dpae_flow_id', FlowId::fromString('flow-1'), TenantId::fromString('t1')],
            'fingerprint' => [new FingerprintType(), 'urssaf_dpae_fingerprint', FlowFingerprint::ofBytes('<flow/>'), TenantId::fromString('t1')],
            default => [new SiretType(), 'urssaf_dpae_siret', Siret::fromString('12345678901234'), TenantId::fromString('t1')],
        };
    }

    #[DataProvider('types')]
    public function testAValueIsStoredAsItsStringAndReadBack(string $case): void
    {
        [$type, $name, $value] = self::sample($case);
        // @mago-expect analysis:mixed-assignment The DBAL conversion is untyped: the assertion below narrows it.
        $stored = $type->convertToDatabaseValue($value, $this->platform);

        static::assertIsString($stored);
        static::assertEquals($value, $type->convertToPHPValue($stored, $this->platform));
        static::assertSame($type::class, DoctrineIntegration::types()[$name] ?? null);
    }

    #[DataProvider('types')]
    public function testNullStaysNull(string $case): void
    {
        [$type] = self::sample($case);
        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
    }

    #[DataProvider('types')]
    public function testAnotherTypeOfValueIsRefused(string $case): void
    {
        [$type, , $value, $other] = self::sample($case);

        $failure = Outcome::of(fn(): mixed => $type->convertToDatabaseValue($other, $this->platform));

        static::assertInstanceOf(InvalidType::class, $failure);
        static::assertSame(
            \sprintf('Could not convert PHP value of type %s to type %s. Expected one of the following types: %s, null.', $other::class, $type::class, $value::class),
            $failure->getMessage(),
        );
    }

    #[DataProvider('types')]
    public function testANonStringIsRefusedWhenReading(string $case): void
    {
        [$type] = self::sample($case);

        $failure = Outcome::of(fn(): mixed => $type->convertToPHPValue(42, $this->platform));

        static::assertInstanceOf(InvalidType::class, $failure);
        static::assertSame(
            \sprintf('Could not convert PHP value 42 to type %s. Expected one of the following types: string, null.', $type::class),
            $failure->getMessage(),
        );
    }

    #[DataProvider('types')]
    public function testAnInvalidStringIsRefusedWhenReading(string $case): void
    {
        [$type] = self::sample($case);

        $failure = Outcome::of(fn(): mixed => $type->convertToPHPValue('', $this->platform));

        static::assertInstanceOf(InvalidFormat::class, $failure);
        static::assertSame(\sprintf('Could not convert database value "" to Doctrine Type %s. Expected format "valid value".', $type::class), $failure->getMessage());
    }

    #[DataProvider('types')]
    public function testItIsAVarchar(string $case): void
    {
        [$type] = self::sample($case);
        static::assertSame('VARCHAR(40)', $type->getSQLDeclaration(['length' => 40], $this->platform));
    }
}
