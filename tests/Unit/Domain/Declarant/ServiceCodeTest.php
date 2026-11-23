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

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceKind;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class ServiceCodeTest extends TestCase
{
    public function testDocumentedValuesAreRecognised(): void
    {
        static::assertSame(25, ServiceCode::direct()->value());
        static::assertSame(ServiceKind::Direct, ServiceCode::direct()->kind());
        static::assertSame(98, ServiceCode::concentrator()->value());
        static::assertSame(ServiceKind::Concentrator, ServiceCode::concentrator()->kind());
        static::assertSame(ServiceKind::Direct, ServiceKind::fromCode(25));
        static::assertSame(ServiceKind::Concentrator, ServiceKind::fromCode(98));
    }

    public function testUnknownValueIsKeptAsIs(): void
    {
        $code = ServiceCode::fromInt(97);

        static::assertSame(97, $code->value());
        static::assertNull($code->kind());
        static::assertNull(ServiceKind::fromCode(97));
    }

    public function testInt32BoundsAreAccepted(): void
    {
        static::assertSame(2_147_483_647, ServiceCode::fromInt(2_147_483_647)->value());
        static::assertSame(-2_147_483_648, ServiceCode::fromInt(-2_147_483_648)->value());
    }

    public function testValueAboveInt32IsRefused(): void
    {
        $this->expectException(InvalidValue::class);
        $this->expectExceptionMessage('signed 32-bit');

        ServiceCode::fromInt(2_147_483_648);
    }

    public function testValueBelowInt32IsRefused(): void
    {
        $this->expectException(InvalidValue::class);

        ServiceCode::fromInt(-2_147_483_649);
    }

    public function testRefusalCarriesTheBounds(): void
    {
        try {
            ServiceCode::fromInt(2_147_483_648);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('service_code.out_of_range', $exception->violationCode());
            static::assertSame(['min' => -2_147_483_648, 'max' => 2_147_483_647], $exception->params());
        }
    }

    public function testEquality(): void
    {
        static::assertTrue(ServiceCode::fromInt(25)->equals(ServiceCode::direct()));
        static::assertFalse(ServiceCode::fromInt(25)->equals(ServiceCode::concentrator()));
    }
}
