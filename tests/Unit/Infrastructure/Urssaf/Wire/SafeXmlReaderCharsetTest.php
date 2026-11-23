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

final class SafeXmlReaderCharsetTest extends TestCase
{
    public function testItHonoursTheEncodingDeclaredByTheDocument(): void
    {
        $latin1 = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?><a>caf\xE9</a>";

        static::assertSame('café', SafeXmlReader::parse($latin1, '')->documentElement?->textContent);
        static::assertSame('café', SafeXmlReader::parse($latin1, 'application/xml; charset=UTF-8')->documentElement?->textContent);
    }

    public function testTheDeclaredEncodingWinsOverTheHeaderCharset(): void
    {
        $utf8 = '<?xml version="1.0" encoding="UTF-8"?><a>café</a>';

        static::assertSame('café', SafeXmlReader::parse($utf8, 'application/xml; charset=ISO-8859-1')->documentElement?->textContent);
        static::assertSame(
            'café',
            SafeXmlReader::parse("\xEF\xBB\xBF" . $utf8, 'application/xml; charset=ISO-8859-1')->documentElement?->textContent,
        );
    }

    public function testTheHeaderCharsetIsHonouredWithoutADeclaration(): void
    {
        $latin1 = "<a>caf\xE9</a>";

        static::assertSame('café', SafeXmlReader::parse($latin1, 'application/xml; charset=ISO-8859-1')->documentElement?->textContent);
        static::assertSame('café', SafeXmlReader::parse($latin1, 'application/xml;charset="iso-8859-1"')->documentElement?->textContent);
        static::assertSame('café', SafeXmlReader::parse($latin1, 'text/xml; Charset = ISO-8859-1; q=1')->documentElement?->textContent);
        static::assertSame(
            'café',
            SafeXmlReader::parse("<?xml version=\"1.0\"?><a>caf\xE9</a>", 'application/xml; charset=ISO-8859-1')->documentElement?->textContent,
        );
    }

    public function testAnAttributeNamedEncodingIsNotADeclaration(): void
    {
        $bytes = "<?xml version=\"1.0\"?><a encoding=\"x\">caf\xE9</a>";

        static::assertSame('café', SafeXmlReader::parse($bytes, 'application/xml; charset=ISO-8859-1')->documentElement?->textContent);
    }

    public function testUtf8IsTheDefaultAndNeedsNoConversion(): void
    {
        static::assertSame('café', SafeXmlReader::parse('<a>café</a>', '')->documentElement?->textContent);
        static::assertSame('café', SafeXmlReader::parse('<a>café</a>', 'application/xml; charset=utf-8')->documentElement?->textContent);
        static::assertSame('café', SafeXmlReader::parse('<a>café</a>', 'application/xml')->documentElement?->textContent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnsupportedCharsets(): iterable
    {
        yield 'unknown' => ['NOT-A-CHARSET'];
        yield 'UUENCODE' => ['UUENCODE'];
        yield 'BASE64' => ['BASE64'];
        yield 'UTF-7' => ['UTF-7'];
        yield 'HTML-ENTITIES' => ['HTML-ENTITIES'];
        yield 'UTF-16' => ['UTF-16'];
        yield 'quoted' => ['"BASE64"'];
    }

    #[DataProvider('provideUnsupportedCharsets')]
    public function testAnUnsupportedCharsetIsRefused(string $charset): void
    {
        $this->expectException(UnexpectedUrssafResponse::class);
        $this->expectExceptionMessage('the charset of the body is not supported');

        SafeXmlReader::parse('<a>PGEvPg==</a>', 'application/xml; charset=' . $charset);
    }

    public function testEverySupportedCharsetIsConverted(): void
    {
        foreach (['ISO-8859-1', 'iso-8859-15', 'Windows-1252', 'US-ASCII', 'utf-8'] as $charset) {
            static::assertSame('abc', SafeXmlReader::parse('<a>abc</a>', 'text/xml; charset=' . $charset)->documentElement?->textContent);
        }
        static::assertSame("\u{20AC}", SafeXmlReader::parse("<a>\x80</a>", 'text/xml; charset=windows-1252')->documentElement?->textContent);
        static::assertSame("\u{20AC}", SafeXmlReader::parse("<a>\xA4</a>", 'text/xml; charset=ISO-8859-15')->documentElement?->textContent);
    }
}
