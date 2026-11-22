<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Id;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\RandomIdGenerator;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingRandomBytes;

final class RandomIdGeneratorTest extends TestCase
{
    public function testItSetsTheVersionAndVariantBitsOfAUuidV4(): void
    {
        $ones = new RandomIdGenerator(new RecordingRandomBytes(str_repeat("\xFF", 16)));
        $zeros = new RandomIdGenerator(new RecordingRandomBytes(str_repeat("\x00", 16)));

        static::assertSame('ffffffff-ffff-4fff-bfff-ffffffffffff', $ones->generate());
        static::assertSame('00000000-0000-4000-8000-000000000000', $zeros->generate());
    }

    public function testItAsksForSixteenBytesAndFormatsThemInFiveGroups(): void
    {
        $source = new RecordingRandomBytes("\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10");

        $id = (new RandomIdGenerator($source))->generate();

        static::assertSame('01020304-0506-4708-890a-0b0c0d0e0f10', $id);
        static::assertSame(16, $source->asked());
    }

    public function testTheSecureSourceGivesDistinctValidIds(): void
    {
        $generator = new RandomIdGenerator(new SecureRandomBytes());
        $first = $generator->generate();

        static::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $first);
        static::assertNotSame($first, $generator->generate());
        static::assertSame(24, \strlen((new SecureRandomBytes())->bytes(24)));
    }
}
