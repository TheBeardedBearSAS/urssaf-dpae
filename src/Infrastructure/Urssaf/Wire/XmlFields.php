<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire;

use DOMDocument;
use DOMElement;

/**
 * Tolerant lookups shared by the XML readers: the XML form of the answers is not published, so a value is accepted as a
 * child element or as an attribute, under any namespace, and whitespace around it is ignored (ADR 0004).
 */
final readonly class XmlFields
{
    /**
     * Descendant elements with this local name, in document order.
     *
     * @return list<DOMElement>
     */
    public static function elements(DOMDocument|DOMElement $scope, string $name): array
    {
        $found = [];
        foreach ($scope->getElementsByTagNameNS('*', $name) as $element) {
            $found[] = $element;
        }

        return $found;
    }

    /**
     * A field of an element: its direct child element of that name, else its attribute of that name. Null when absent
     * or blank.
     */
    public static function field(DOMElement $element, string $name): ?string
    {
        foreach ($element->childNodes as $child) {
            if (!$child instanceof DOMElement || $child->localName !== $name) {
                continue;
            }
            $text = self::clean($child->textContent);
            if ($text !== null) {
                return $text;
            }
        }

        return self::clean($element->getAttribute($name));
    }

    /**
     * The first non-blank value of that name anywhere in the document: an element of that name, else an attribute.
     */
    public static function firstValue(DOMDocument $document, string $name): ?string
    {
        foreach (self::elements($document, $name) as $element) {
            $text = self::clean($element->textContent);
            if ($text !== null) {
                return $text;
            }
        }
        foreach (self::elements($document, '*') as $element) {
            $text = self::clean($element->getAttribute($name));
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    public static function clean(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
