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
use DOMDocumentType;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * The only entry point to ext-dom for the answers of URSSAF (ADR 0004).
 *
 * Same libxml options as {@see DpaeFlow::LIBXML_OPTIONS} (`LIBXML_NONET`; no entity substitution, no DTD loading, no
 * huge nodes) and refusal of any document carrying a DOCTYPE. The encoding declared by the XML declaration wins (the
 * parser converts it); without a declaration the `charset` of the `Content-Type` is honoured (UTF-8, ISO-8859-1, ISO-8859-15,
 * Windows-1252 or US-ASCII; any other one is refused), then XML's UTF-8 default. Values handed to the callers are UTF-8. libxml's global error state is left as it was found.
 */
final readonly class SafeXmlReader
{
    private const DECLARED_ENCODING = '/^(?:\xEF\xBB\xBF)?<\?xml[^>]*\sencoding\s*=/D';
    /** Supported header charsets (upper case) and the mbstring name each is converted from. */
    private const CHARSETS = [
        'UTF-8' => 'UTF-8',
        'ISO-8859-1' => 'ISO-8859-1',
        'ISO-8859-15' => 'ISO-8859-15',
        'WINDOWS-1252' => 'Windows-1252',
        'US-ASCII' => 'ASCII',
    ];
    private const CHARSET = '/;\s*charset\s*=\s*"?([^\s";]+)/i';

    /**
     * @param string $contentType the `Content-Type` header of the answer, an empty string when there is none
     *
     * @throws UnexpectedUrssafResponse when the body is not a well-formed XML document, carries a DOCTYPE or has an
     *                                  unsupported charset
     */
    public static function parse(string $bytes, string $contentType): DOMDocument
    {
        // A NUL byte is not an XML character; ext-dom would throw a ValueError instead of reporting it.
        if (trim($bytes) === '' || str_contains($bytes, "\0")) {
            throw UnexpectedUrssafResponse::invalidPayload('the body is not a readable XML document');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            /** @var non-empty-string $source The body is not blank, hence neither is its UTF-8 form. */
            $source = self::toUtf8($bytes, $contentType);
            $loaded = $document->loadXML($source, DpaeFlow::LIBXML_OPTIONS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded) {
            throw UnexpectedUrssafResponse::invalidPayload('the body is not well-formed XML');
        }
        if ($document->doctype instanceof DOMDocumentType) {
            throw UnexpectedUrssafResponse::invalidPayload('the body declares a DOCTYPE');
        }

        return $document;
    }

    private static function toUtf8(string $bytes, string $contentType): string
    {
        $charset = [];
        if (
            preg_match(self::DECLARED_ENCODING, $bytes) === 1
            || preg_match(self::CHARSET, $contentType, $charset) !== 1
        ) {
            return $bytes;
        }

        // The charset comes from an untrusted header: only a short list of single-byte charsets is converted, so that
        // mb_convert_encoding() never meets a transfer encoding (BASE64, UUENCODE, HTML-ENTITIES, UTF-7...) or a charset
        // it does not know.
        $name = self::CHARSETS[strtoupper($charset[1])] ?? null;
        if ($name === null) {
            throw UnexpectedUrssafResponse::invalidPayload('the charset of the body is not supported');
        }
        if ($name === 'UTF-8') {
            return $bytes;
        }

        $converted = mb_convert_encoding($bytes, 'UTF-8', $name);
        // The body is not blank and holds no NUL byte, so a single-byte charset always yields some text (any byte
        // maps to a character, an invalid one to `?`), so an empty result cannot happen and is not testable.
        \assert(\is_string($converted), 'A supported charset converts without failing.');
        \assert($converted !== '', 'A non-blank body converts to a non-blank one.');

        return $converted;
    }
}
