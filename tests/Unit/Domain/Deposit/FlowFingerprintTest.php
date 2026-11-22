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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class FlowFingerprintTest extends TestCase
{
    private const HELLO = '2cf24dba5fb0a30e26e83b2ac5b9e29e1b161e5c1fa7425e73043362938b9824';

    public function testItIsTheSha256OfTheBytes(): void
    {
        static::assertSame(self::HELLO, FlowFingerprint::ofBytes('hello')->value());
        static::assertSame('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', FlowFingerprint::ofBytes('')->value());
    }

    public function testItIsRestoredFromItsHexValue(): void
    {
        $fingerprint = FlowFingerprint::fromString(self::HELLO);

        static::assertSame(self::HELLO, $fingerprint->value());
        static::assertTrue($fingerprint->equals(FlowFingerprint::ofBytes('hello')));
        static::assertFalse($fingerprint->equals(FlowFingerprint::ofBytes('Hello')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidHex(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [substr(self::HELLO, 1)];
        yield 'too long' => [self::HELLO . '0'];
        yield 'upper case' => [strtoupper(self::HELLO)];
        yield 'not hex' => [str_repeat('g', 64)];
        yield 'trailing newline' => [self::HELLO . "\n"];
        yield 'leading text' => ['x' . self::HELLO];
    }

    #[DataProvider('provideInvalidHex')]
    public function testInvalidHexIsRefused(string $hex): void
    {
        try {
            FlowFingerprint::fromString($hex);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame('flow_fingerprint.invalid_format', $exception->violationCode());
        }
    }
}
