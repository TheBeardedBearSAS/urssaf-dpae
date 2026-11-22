<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Wire;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\SafeXmlReader;

final class SafeXmlReaderTest extends TestCase
{
    public function testItReadsAWellFormedDocument(): void
    {
        $document = SafeXmlReader::parse('<?xml version="1.0" encoding="UTF-8"?><retours><flux/></retours>', 'application/xml');

        static::assertSame('retours', $document->documentElement?->localName);
        static::assertNull($document->doctype);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => [" \n\t"];
        yield 'NUL byte' => ["<a>\0</a>"];
        yield 'not XML' => ['{"flux":[]}'];
        yield 'HTML page' => ['<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><body>404</body></html>'];
        yield 'unclosed' => ['<a><b></a>'];
        yield 'two roots' => ['<a/><b/>'];
        yield 'unknown declared encoding' => ['<?xml version="1.0" encoding="NOT-AN-ENCODING"?><a/>'];
        yield 'invalid UTF-8 bytes under the default encoding' => ["<a>\xFF</a>"];
        yield 'invalid bytes for the declared encoding' => ["<?xml version=\"1.0\" encoding=\"UTF-8\"?><a>\xFF</a>"];
    }

    public function testInvalidBytesAreNotRepairedWhenTheHeaderSaysUtf8(): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        SafeXmlReader::parse("<a>\xFF</a>", 'application/xml; charset=utf-8');
    }

    #[DataProvider('provideUnreadableBodies')]
    public function testItRefusesAnUnreadableBody(string $bytes): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);

        SafeXmlReader::parse($bytes, 'application/xml');
    }

    public function testItNamesTheDoctypeAndTheMalformedBody(): void
    {
        try {
            SafeXmlReader::parse('<!DOCTYPE a><a/>', '');
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertSame('Unexpected URSSAF response: the body declares a DOCTYPE.', $exception->getMessage());
        }
        try {
            SafeXmlReader::parse('<a>', '');
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertSame('Unexpected URSSAF response: the body is not well-formed XML.', $exception->getMessage());
        }
        try {
            SafeXmlReader::parse(" \n", '');
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertSame('Unexpected URSSAF response: the body is not a readable XML document.', $exception->getMessage());
        }
        try {
            SafeXmlReader::parse('', '');
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertSame('Unexpected URSSAF response: the body is not a readable XML document.', $exception->getMessage());
        }
    }

    public function testItLeavesTheLibxmlStateAsItFoundIt(): void
    {
        foreach ([true, false] as $initial) {
            libxml_use_internal_errors($initial);
            libxml_clear_errors();

            SafeXmlReader::parse('<a/>', '');
            static::assertSame($initial, libxml_use_internal_errors($initial));
            static::assertSame([], libxml_get_errors());

            try {
                SafeXmlReader::parse('<a>', '');
            } catch (UnexpectedUrssafResponse $exception) {
                static::assertStringStartsWith('Unexpected URSSAF response', $exception->getMessage());
            }
            static::assertSame($initial, libxml_use_internal_errors($initial));
            static::assertSame([], libxml_get_errors());

            try {
                SafeXmlReader::parse('<a/>', 'application/xml; charset=UUENCODE');
            } catch (UnexpectedUrssafResponse $exception) {
                static::assertStringStartsWith('Unexpected URSSAF response', $exception->getMessage());
            }
            static::assertSame($initial, libxml_use_internal_errors($initial));
        }
        libxml_use_internal_errors(false);
    }
}
