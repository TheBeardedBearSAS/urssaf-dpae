<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Exception;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\CredentialsNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;

final class NotSerializableExceptionsTest extends TestCase
{
    public function testCredentialsNotSerializable(): void
    {
        $serializing = CredentialsNotSerializable::serializing('A');
        $unserializing = CredentialsNotSerializable::unserializing('A');

        static::assertSame('A must never be serialized.', $serializing->getMessage());
        static::assertSame('A must never be unserialized.', $unserializing->getMessage());
    }

    public function testPayloadNotSerializable(): void
    {
        $serializing = PayloadNotSerializable::serializing('A');
        $unserializing = PayloadNotSerializable::unserializing('A');

        static::assertSame('A must never be serialized.', $serializing->getMessage());
        static::assertSame('A must never be unserialized.', $unserializing->getMessage());
    }
}
