<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

use DOMDocument;
use DOMDocumentType;
use DOMElement;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Exception\ValidationFailed;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Validation\ValidationResult;
use TheBeardedBear\UrssafDpae\Domain\Validation\Violation;

/**
 * A DPAE flow as it is deposited: the XML bytes verbatim plus the mode it is deposited in (ADR 0003, ADR 0007).
 *
 * Checked on creation, in this order: not blank (`flow.empty`); XML rather than a flat flow (`flow.format_not_supported`:
 * the first significant byte, after an optional UTF-8 BOM and XML whitespace, is not `<`); well-formed
 * (`flow.not_well_formed`); no DOCTYPE, so no entity is ever defined or loaded (`flow.dtd_not_allowed`); root
 * `FR_DUE_Upload` (`flow.unexpected_root`); and `FR_DUE_Upload.Test.Indicator`, child of the root, strictly equal to
 * the indicator expected for the mode (`flow.declaration_mode_mismatch`, also when the element is missing). Nothing is
 * rewritten.
 *
 * ext-dom is used with the same libxml options as the safe XML reader of the infrastructure (ADR 0004 amendment):
 * {@see self::LIBXML_OPTIONS}.
 *
 * The bytes contain personal data: `__debugInfo()` never shows them and the object cannot be serialized.
 */
final readonly class DpaeFlow
{
    use ForbidsSerialization;

    public const ROOT_ELEMENT = 'FR_DUE_Upload';
    public const INDICATOR_ELEMENT = 'FR_DUE_Upload.Test.Indicator';

    /**
     * No `LIBXML_NOENT` / `LIBXML_DTDLOAD` / `LIBXML_DTDATTR` / `LIBXML_PARSEHUGE`: nothing is substituted or loaded;
     * `LIBXML_NONET` forbids the network.
     */
    public const LIBXML_OPTIONS = \LIBXML_NONET;

    private const UTF8_BOM = "\xEF\xBB\xBF";

    private function __construct(
        private string $bytes,
        private DeclarationMode $mode,
        private FlowFingerprint $fingerprint,
    ) {}

    /**
     * @throws ValidationFailed `flow.empty`, `flow.format_not_supported`, `flow.not_well_formed`,
     *                          `flow.dtd_not_allowed`, `flow.unexpected_root` or `flow.declaration_mode_mismatch`
     */
    public static function fromXml(string $bytes, DeclarationMode $mode, TestIndicators $indicators): self
    {
        $head = ltrim(str_starts_with($bytes, self::UTF8_BOM) ? substr($bytes, 3) : $bytes, " \t\r\n");
        if ($head === '') {
            throw self::refuse('flow.empty', 'The flow is empty.');
        }
        if ($head[0] !== '<') {
            throw self::refuse('flow.format_not_supported', 'Only XML flows are supported, not the flat format.');
        }

        // The head is not empty, hence neither are the bytes.
        /** @var non-empty-string $bytes */
        // A NUL byte is not an XML character; ext-dom would throw a ValueError instead of reporting it.
        $document = str_contains($bytes, "\0") ? null : self::parse($bytes);
        if (!$document instanceof DOMDocument) {
            throw self::refuse('flow.not_well_formed', 'The flow is not well-formed XML.');
        }
        if ($document->doctype instanceof DOMDocumentType) {
            throw self::refuse('flow.dtd_not_allowed', 'The flow must not declare a DOCTYPE.');
        }
        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->tagName !== self::ROOT_ELEMENT) {
            throw self::refuse('flow.unexpected_root', 'The root element of the flow must be FR_DUE_Upload.');
        }
        if (self::indicatorOf($root) !== $indicators->forMode($mode)) {
            throw self::refuse(
                'flow.declaration_mode_mismatch',
                'The test indicator of the flow does not match the declaration mode.',
                ['mode' => $mode->value],
            );
        }

        return new self($bytes, $mode, FlowFingerprint::ofBytes($bytes));
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function mode(): DeclarationMode
    {
        return $this->mode;
    }

    public function fingerprint(): FlowFingerprint
    {
        return $this->fingerprint;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['mode' => $this->mode, 'fingerprint' => $this->fingerprint->value(), 'size' => \strlen($this->bytes)];
    }

    /**
     * @param non-empty-string $bytes
     */
    private static function parse(string $bytes): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($bytes, self::LIBXML_OPTIONS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private static function indicatorOf(DOMElement $root): ?string
    {
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->tagName === self::INDICATOR_ELEMENT) {
                return $child->textContent;
            }
        }

        return null;
    }

    /**
     * @param array<string, scalar> $params
     */
    private static function refuse(string $code, string $message, array $params = []): ValidationFailed
    {
        return ValidationFailed::withResult(ValidationResult::of(Violation::error('flow', $code, $message, $params)));
    }
}
