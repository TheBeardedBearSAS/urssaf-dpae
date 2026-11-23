<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Return;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContentType;

final class ReturnContentTest extends TestCase
{
    public function testItKeepsTheRawBytesAndTheRawContentType(): void
    {
        $bytes = "%PDF-1.4\x00\xFF\xFE binary";

        $content = ReturnContent::of($bytes, 'Application/PDF ; x=y');

        static::assertSame($bytes, $content->bytes());
        static::assertSame('Application/PDF ; x=y', $content->contentType());
        static::assertSame(ReturnContentType::Pdf, $content->type());
        static::assertSame(\strlen($bytes), $content->size());
    }

    public function testASingleByteIsContent(): void
    {
        static::assertSame(1, ReturnContent::of('0', 'application/xml')->size());
    }

    public function testAnUnknownContentTypeIsKeptAndHasNoType(): void
    {
        $content = ReturnContent::of('x', 'text/html');

        static::assertSame('text/html', $content->contentType());
        static::assertNull($content->type());
        static::assertNull(ReturnContent::of('x', '')->type());
    }

    public function testItRejectsEmptyContent(): void
    {
        try {
            ReturnContent::of('', 'application/xml');
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('return_content.empty', $e->violationCode());
        }
    }

    /**
     * @return iterable<string, array{string, ?ReturnContentType}>
     */
    public static function provideHeaders(): iterable
    {
        yield 'xml' => ['application/xml', ReturnContentType::Xml];
        yield 'xml with charset' => ['application/xml;charset=ISO-8859-1', ReturnContentType::Xml];
        yield 'xml spaced upper' => ['  Application/XML ; charset=UTF-8', ReturnContentType::Xml];
        yield 'pdf' => ['application/pdf', ReturnContentType::Pdf];
        yield 'pdf with parameters' => ['application/pdf; x=y;z=w', ReturnContentType::Pdf];
        yield 'text xml is not published' => ['text/xml', null];
        yield 'html' => ['text/html', null];
        yield 'empty' => ['', null];
        yield 'json' => ['application/json', null];
        yield 'prefix only' => ['application/xmlx', null];
        yield 'octet stream' => ['application/octet-stream', null];
    }

    #[DataProvider('provideHeaders')]
    public function testItReadsTheContentTypeHeader(string $header, ?ReturnContentType $expected): void
    {
        static::assertSame($expected, ReturnContentType::fromHeader($header));
    }

    public function testDebugInfoShowsOnlyTheSizeTheDigestAndTheContentType(): void
    {
        $content = ReturnContent::of('<secret-name/>', 'application/xml');

        static::assertSame(
            ['size' => 14, 'sha256' => hash('sha256', '<secret-name/>'), 'contentType' => 'application/xml'],
            $content->__debugInfo(),
        );
        static::assertStringNotContainsString('secret-name', print_r($content, true));
    }
}
