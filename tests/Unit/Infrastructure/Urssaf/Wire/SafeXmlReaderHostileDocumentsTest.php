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

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\SafeXmlReader;

final class SafeXmlReaderHostileDocumentsTest extends TestCase
{
    private const CANARY_PATH = __DIR__ . '/../../../../Fixtures/xxe/canary.txt';

    /**
     * The corpus of DpaeFlowLibxmlOptionsTest (ADR 0004 amendment): the reader and the flow share the libxml options.
     *
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideHostileDocuments(): iterable
    {
        yield 'external file entity' => [
            '<!DOCTYPE retours [<!ENTITY e SYSTEM "file://' . self::CANARY_PATH . '">]><retours>&e;</retours>',
        ];
        yield 'external network entity' => [
            '<!DOCTYPE retours [<!ENTITY e SYSTEM "http://127.0.0.1:9/x">]><retours>&e;</retours>',
        ];
        yield 'internal entity' => ['<!DOCTYPE retours [<!ENTITY e "inline">]><retours>&e;</retours>'];
        yield 'external subset' => ['<!DOCTYPE retours SYSTEM "http://127.0.0.1:9/x.dtd"><retours/>'];
        yield 'harmless doctype' => ['<!DOCTYPE retours><retours/>'];
        yield 'billion laughs' => [
            '<!DOCTYPE l [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;&a;&a;">'
            . '<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;&b;&b;">]><l>&c;</l>',
        ];
    }

    #[DataProvider('provideHostileDocuments')]
    public function testItRefusesEveryHostileDocument(string $bytes): void
    {
        try {
            SafeXmlReader::parse($bytes, 'application/xml');
            static::fail('Expected a refusal.');
        } catch (UnexpectedUrssafResponse $exception) {
            static::assertStringNotContainsString('XXE-CANARY', $exception->getMessage());
        }
    }

    /**
     * @param non-empty-string $bytes
     */
    #[DataProvider('provideHostileDocuments')]
    public function testItSharesTheLibxmlOptionsOfTheFlow(string $bytes): void
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadXML($bytes, DpaeFlow::LIBXML_OPTIONS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        static::assertStringNotContainsString('XXE-CANARY', (string) $document->saveXML());
    }
}
