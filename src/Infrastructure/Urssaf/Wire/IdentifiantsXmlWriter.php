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
use DOMText;
use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Body of `POST /authentifier_dpae` (ADR 0001, api-reference sections 2.1 and 5.4). Inferred shape, no official
 * example: root `identifiants`, no namespace, children `siret`, `nom`, `prenom`, `motdepasse`, `service` in the order of
 * the Swagger properties, text escaped by ext-dom, declared encoding configurable (default UTF-8).
 *
 * A character the declared encoding cannot hold is written as a numeric character reference (lossless). A character
 * XML 1.0 cannot carry at all is refused: ext-dom would silently drop it and alter the password. Neither the exception
 * messages nor anything else here ever carries a credential value.
 */
final readonly class IdentifiantsXmlWriter implements CredentialsXmlWriter
{
    public const ROOT_ELEMENT = 'identifiants';
    public const DEFAULT_ENCODING = 'UTF-8';

    /** Supported declared encodings (upper-case key => name written in the declaration), same list as SafeXmlReader. */
    private const ENCODINGS = [
        'UTF-8' => 'UTF-8',
        'ISO-8859-1' => 'ISO-8859-1',
        'ISO-8859-15' => 'ISO-8859-15',
        'WINDOWS-1252' => 'windows-1252',
        'US-ASCII' => 'US-ASCII',
    ];

    private const SUBJECT = 'authentication_encoding';
    private const NOT_XML_CHARACTER = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    private function __construct(
        private string $encoding,
    ) {}

    public static function utf8(): self
    {
        return new self(self::DEFAULT_ENCODING);
    }

    /**
     * @throws InvalidValue `authentication_encoding.empty` or `authentication_encoding.invalid_format` when the encoding
     *                      is not one of the supported ones
     */
    public static function withEncoding(string $encoding): self
    {
        if (trim($encoding) === '') {
            throw InvalidValue::empty(self::SUBJECT);
        }

        // A fixed list, not a probe of ext-dom: whether libxml warns about an unknown encoding depends on its version.
        $canonical = self::ENCODINGS[strtoupper(trim($encoding))] ?? null;
        if ($canonical === null) {
            throw InvalidValue::invalidFormat(self::SUBJECT, 'UTF-8, ISO-8859-1, ISO-8859-15, windows-1252 or US-ASCII');
        }

        return new self($canonical);
    }

    public function write(
        #[SensitiveParameter]
        DeclarantCredentials $credentials,
    ): string {
        $values = [
            'siret' => $credentials->siret()->value(),
            'nom' => $credentials->nom(),
            'prenom' => $credentials->prenom(),
            'motdepasse' => $credentials->motdepasse(),
            'service' => (string) $credentials->service()->value(),
        ];

        $document = new DOMDocument('1.0', $this->encoding);
        $root = new DOMElement(self::ROOT_ELEMENT);
        $document->appendChild($root);
        foreach ($values as $name => $value) {
            if (preg_match(self::NOT_XML_CHARACTER, $value) === 1) {
                throw InvalidValue::because(
                    'authentication.invalid_character',
                    'A credential contains a character that XML cannot carry.',
                    ['field' => $name],
                );
            }
            $element = new DOMElement($name);
            $root->appendChild($element);
            $element->appendChild(new DOMText($value));
        }

        $xml = $document->saveXML();
        \assert(\is_string($xml), 'The encoding was probed on creation, so saveXML() cannot fail.');

        return $xml;
    }
}
