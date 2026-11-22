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
use DOMElement;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\XmlFields;

final class XmlFieldsTest extends TestCase
{
    /**
     * @param non-empty-string $xml
     */
    private static function load(string $xml): DOMDocument
    {
        $document = new DOMDocument();
        $document->loadXML($xml);

        return $document;
    }

    private static function root(DOMDocument $document): DOMElement
    {
        $root = $document->documentElement;
        static::assertInstanceOf(DOMElement::class, $root);

        return $root;
    }

    public function testElementsAreFoundAtAnyDepthInDocumentOrderUnderAnyNamespace(): void
    {
        $document = self::load('<r xmlns:n="urn:x"><a i="1"><a i="2"/></a><n:a i="3"/><b><a i="4"/></b></r>');

        $ids = array_map(static fn(DOMElement $e): string => $e->getAttribute('i'), XmlFields::elements($document, 'a'));
        static::assertSame(['1', '2', '3', '4'], $ids);
        static::assertSame([], XmlFields::elements($document, 'missing'));

        $scoped = XmlFields::elements(self::root($document), 'a');
        static::assertCount(4, $scoped);
        $first = $scoped[0];
        static::assertCount(1, XmlFields::elements($first, 'a'));
    }

    public function testAFieldIsAChildElementOrAnAttribute(): void
    {
        $document = self::load('<r><x>  value  </x></r>');
        static::assertSame('value', XmlFields::field(self::root($document), 'x'));

        $document = self::load('<r x=" value "/>');
        static::assertSame('value', XmlFields::field(self::root($document), 'x'));
    }

    public function testTheChildElementWinsOverTheAttribute(): void
    {
        $document = self::load('<r x="attribute"><x>child</x></r>');

        static::assertSame('child', XmlFields::field(self::root($document), 'x'));
    }

    public function testABlankChildFallsBackToTheAttributeAndThenToNull(): void
    {
        static::assertSame('attribute', XmlFields::field(self::root(self::load('<r x="attribute"><x> </x></r>')), 'x'));
        static::assertNull(XmlFields::field(self::root(self::load('<r x=" "><x/></r>')), 'x'));
        static::assertNull(XmlFields::field(self::root(self::load('<r/>')), 'x'));
    }

    public function testAFieldOnlyLooksAtDirectChildren(): void
    {
        $document = self::load('<r><g><x>deep</x></g></r>');

        static::assertNull(XmlFields::field(self::root($document), 'x'));
    }

    public function testAFieldIgnoresTextAndNamespacePrefixes(): void
    {
        $document = self::load('<r xmlns:n="urn:x">text<n:x>prefixed</n:x></r>');

        static::assertSame('prefixed', XmlFields::field(self::root($document), 'x'));
        static::assertNull(XmlFields::field(self::root($document), 'n:x'));
    }

    public function testTheFirstValueIsAnElementThenAnAttribute(): void
    {
        static::assertSame('one', XmlFields::firstValue(self::load('<r><a><v> </v></a><b><v>one</v><v>two</v></b></r>'), 'v'));
        static::assertSame('attr', XmlFields::firstValue(self::load('<r><a v=" "/><b v="attr"/><c v="other"/></r>'), 'v'));
        static::assertSame('root', XmlFields::firstValue(self::load('<r v="root"/>'), 'v'));
        static::assertSame('element', XmlFields::firstValue(self::load('<r v="attribute"><v>element</v></r>'), 'v'));
        static::assertNull(XmlFields::firstValue(self::load('<r><a/></r>'), 'v'));
    }

    public function testCleanTrimsAndMapsBlankToNull(): void
    {
        static::assertSame('a b', XmlFields::clean(" \n a b\t"));
        static::assertNull(XmlFields::clean(" \n\t"));
        static::assertNull(XmlFields::clean(''));
        static::assertSame('0', XmlFields::clean('0'));
    }
}
