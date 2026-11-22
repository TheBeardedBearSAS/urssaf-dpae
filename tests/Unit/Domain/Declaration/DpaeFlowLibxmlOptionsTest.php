<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declaration;

use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;

/**
 * ADR 0004 amendment: the Domain may use ext-dom, with the libxml options of the infrastructure's `SafeXmlReader`
 * (`LIBXML_NONET`, no DTD loading, no entity substitution). The corpus below is the shared contract: the reader's own
 * test must run the same documents with {@see DpaeFlow::LIBXML_OPTIONS}.
 */
final class DpaeFlowLibxmlOptionsTest extends TestCase
{
    private const CANARY_PATH = __DIR__ . '/../../../Fixtures/xxe/canary.txt';

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function provideHostileDocuments(): iterable
    {
        yield 'external file entity' => [
            '<!DOCTYPE FR_DUE_Upload [<!ENTITY e SYSTEM "file://' . self::CANARY_PATH . '">]><FR_DUE_Upload>&e;</FR_DUE_Upload>',
        ];
        yield 'external network entity' => [
            '<!DOCTYPE FR_DUE_Upload [<!ENTITY e SYSTEM "http://127.0.0.1:9/x">]><FR_DUE_Upload>&e;</FR_DUE_Upload>',
        ];
        yield 'internal entity' => ['<!DOCTYPE FR_DUE_Upload [<!ENTITY e "inline">]><FR_DUE_Upload>&e;</FR_DUE_Upload>'];
        yield 'external subset' => ['<!DOCTYPE FR_DUE_Upload SYSTEM "http://127.0.0.1:9/x.dtd"><FR_DUE_Upload/>'];
    }

    /**
     * @param non-empty-string $bytes
     */
    #[DataProvider('provideHostileDocuments')]
    public function testNoEntityIsSubstitutedAndNoDtdIsLoaded(string $bytes): void
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            static::assertTrue($document->loadXML($bytes, DpaeFlow::LIBXML_OPTIONS));
            static::assertStringNotContainsString('XXE-CANARY', (string) $document->documentElement?->textContent);
            static::assertNotNull($document->doctype);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    #[DataProvider('provideHostileDocuments')]
    public function testTheFlowRefusesEveryHostileDocument(string $bytes): void
    {
        try {
            DpaeFlow::fromXml($bytes, DeclarationMode::Test, TestIndicators::defaults());
            static::fail('Expected a refusal.');
        } catch (ValidationFailed $exception) {
            static::assertSame('flow.dtd_not_allowed', $exception->result()->errors()[0]->code());
        }
    }
}
