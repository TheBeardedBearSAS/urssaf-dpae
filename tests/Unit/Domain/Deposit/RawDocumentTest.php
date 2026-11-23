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
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class RawDocumentTest extends TestCase
{
    public function testItKeepsTheBytesAndTheContentTypeVerbatim(): void
    {
        $document = RawDocument::of(" <envoi/>\n", 'Application/XML ; charset=ISO-8859-1');

        static::assertSame(" <envoi/>\n", $document->bytes());
        static::assertSame('Application/XML ; charset=ISO-8859-1', $document->contentType());
        static::assertSame(10, $document->size());
    }

    public function testTheContentTypeMayBeEmpty(): void
    {
        static::assertSame('', RawDocument::of('x', '')->contentType());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBlankBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'line feed' => ["\n"];
    }

    #[DataProvider('provideBlankBodies')]
    public function testItRejectsABlankBody(string $bytes): void
    {
        try {
            RawDocument::of($bytes, 'application/xml');
            static::fail('InvalidValue expected.');
        } catch (InvalidValue $e) {
            static::assertSame('raw_document.empty', $e->violationCode());
        }
    }

    public function testDebugInfoShowsOnlyTheSizeTheDigestAndTheContentType(): void
    {
        $document = RawDocument::of('<secret-name/>', 'application/xml');

        static::assertSame(
            ['size' => 14, 'sha256' => hash('sha256', '<secret-name/>'), 'contentType' => 'application/xml'],
            $document->__debugInfo(),
        );
        static::assertStringNotContainsString('secret-name', print_r($document, true));
    }
}
