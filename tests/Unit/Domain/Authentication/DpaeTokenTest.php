<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Authentication;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Exception\CredentialsNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class DpaeTokenTest extends TestCase
{
    private const VALUE = 'VGVzdFRva2VuRXhhbXBsZQ==';

    public function testBodyIsUsedVerbatim(): void
    {
        static::assertSame(self::VALUE, DpaeToken::fromResponseBody(self::VALUE)->reveal());
    }

    public function testSurroundingAsciiWhitespaceIsRemoved(): void
    {
        static::assertSame(self::VALUE, DpaeToken::fromResponseBody(" \t\r\n\v\f" . self::VALUE . "\n \r\t\v\f")->reveal());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedBodies(): iterable
    {
        yield 'empty' => ['', 'dpae_token.empty'];
        yield 'whitespace only' => [" \r\n\t\v\f", 'dpae_token.empty'];
        yield 'inner newline' => ["abc\ndef", 'dpae_token.invalid_format'];
        yield 'inner carriage return' => ["abc\rdef", 'dpae_token.invalid_format'];
        yield 'header injection' => ["abc\r\nX-Evil: 1", 'dpae_token.invalid_format'];
        yield 'inner nul byte' => ["abc\0def", 'dpae_token.invalid_format'];
        yield 'trailing nul byte' => ["abc\0", 'dpae_token.invalid_format'];
        yield 'leading nul byte' => ["\0abc", 'dpae_token.invalid_format'];
        yield 'last control below space' => ["abc\x1Fdef", 'dpae_token.invalid_format'];
        yield 'delete character' => ["abc\x7Fdef", 'dpae_token.invalid_format'];
        yield 'trailing delete character' => ["abc\x7F", 'dpae_token.invalid_format'];
    }

    #[DataProvider('provideRefusedBodies')]
    public function testControlCharactersAreRefused(string $body, string $code): void
    {
        try {
            DpaeToken::fromResponseBody($body);
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
            static::assertStringNotContainsString('abc', $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAcceptedBodies(): iterable
    {
        yield 'inner space (first character above control)' => ['abc def'];
        yield 'tilde (last printable ASCII)' => ['abc~def'];
        yield 'first byte above ASCII' => ["abc\x80def"];
        yield 'multibyte character' => ['abcédef'];
        yield 'bounds of the visible range' => ['!~'];
        yield 'single character' => ['a'];
    }

    #[DataProvider('provideAcceptedBodies')]
    public function testEverythingButControlCharactersIsAccepted(string $body): void
    {
        static::assertSame($body, DpaeToken::fromResponseBody($body)->reveal());
    }

    public function testItIsNeverStringable(): void
    {
        static::assertFalse((new ReflectionClass(DpaeToken::class))->hasMethod('__toString'));
    }

    public function testDebugInfoAndDumpsMaskTheValue(): void
    {
        $token = DpaeToken::fromResponseBody(self::VALUE);

        static::assertSame(['value' => '***'], $token->__debugInfo());
        static::assertStringNotContainsString(self::VALUE, print_r($token, true));
    }

    public function testItCannotBeSerialized(): void
    {
        try {
            serialize(DpaeToken::fromResponseBody(self::VALUE));
            static::fail('Expected a refusal.');
        } catch (CredentialsNotSerializable $exception) {
            static::assertSame(DpaeToken::class . ' must never be serialized.', $exception->getMessage());
        }
    }

    public function testItCannotBeUnserialized(): void
    {
        try {
            unserialize('O:' . \strlen(DpaeToken::class) . ':"' . DpaeToken::class . '":0:{}');
            static::fail('Expected a refusal.');
        } catch (CredentialsNotSerializable $exception) {
            static::assertSame(DpaeToken::class . ' must never be unserialized.', $exception->getMessage());
        }
    }
}
