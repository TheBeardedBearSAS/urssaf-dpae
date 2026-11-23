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
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of the deposit (schema `envoi`, ADR 0005): the AEE (HTTP 200) or the rejection notice (HTTP 422).
 * The caller, who knows the HTTP status, picks {@see self::receipt()} or {@see self::rejection()}.
 *
 * No official example exists (api-reference section 5.9), so extraction is best effort and every field is nullable: the
 * flow id, the declarant SIRET (`siret`, looked up under `declarant` first, Swagger `envoi_identification.declarant.siret`,
 * then anywhere), the generation date and the reject reasons. The names of the inferred ones (flow id, generation date,
 * reasons) are those of {@see EnvoiVocabulary}, overridable by the host; the flow id falls back on the `id` of the first
 * `flux` (Swagger). Each is read as an element or an attribute; an absent or unusable one is null, never an error. The
 * answer is kept verbatim as the `RawDocument`.
 */
final readonly class EnvoiXmlReader
{
    /**
     * @param string $contentType the `Content-Type` header of the answer, an empty string when there is none
     *
     * @throws UnexpectedUrssafResponse when the body is not a readable XML document
     */
    public static function receipt(string $bytes, string $contentType, WireDate $dates): ElectronicReceipt
    {
        return self::receiptWith($bytes, $contentType, $dates, EnvoiVocabulary::defaults());
    }

    /**
     * @throws UnexpectedUrssafResponse when the body is not a readable XML document
     */
    public static function receiptWith(
        string $bytes,
        string $contentType,
        WireDate $dates,
        EnvoiVocabulary $vocabulary,
    ): ElectronicReceipt {
        $document = SafeXmlReader::parse($bytes, $contentType);

        return ElectronicReceipt::of(
            self::flowId($document, $vocabulary),
            self::siret($document),
            self::generatedAt($document, $dates, $vocabulary),
            RawDocument::of($bytes, $contentType),
        );
    }

    /**
     * @throws UnexpectedUrssafResponse when the body is not a readable XML document
     */
    public static function rejection(string $bytes, string $contentType, WireDate $dates): RejectionNotice
    {
        return self::rejectionWith($bytes, $contentType, $dates, EnvoiVocabulary::defaults());
    }

    /**
     * @throws UnexpectedUrssafResponse when the body is not a readable XML document
     */
    public static function rejectionWith(
        string $bytes,
        string $contentType,
        WireDate $dates,
        EnvoiVocabulary $vocabulary,
    ): RejectionNotice {
        $document = SafeXmlReader::parse($bytes, $contentType);

        return RejectionNotice::of(
            self::flowId($document, $vocabulary),
            self::siret($document),
            self::generatedAt($document, $dates, $vocabulary),
            RawDocument::of($bytes, $contentType),
            self::reasons($document, $vocabulary),
        );
    }

    private static function flowId(DOMDocument $document, EnvoiVocabulary $vocabulary): ?FlowId
    {
        $flux = XmlFields::elements($document, 'flux')[0] ?? null;
        $value = XmlFields::firstValue($document, $vocabulary->flowId()) ?? ($flux === null ? null : XmlFields::field($flux, 'id'));
        if ($value === null) {
            return null;
        }

        try {
            return FlowId::fromString($value);
        } catch (InvalidValue) {
            return null;
        }
    }

    private static function siret(DOMDocument $document): ?Siret
    {
        $value = self::declarantSiret($document) ?? XmlFields::firstValue($document, 'siret');
        if ($value === null) {
            return null;
        }

        try {
            return Siret::fromString($value);
        } catch (InvalidValue) {
            return null;
        }
    }

    private static function declarantSiret(DOMDocument $document): ?string
    {
        foreach (XmlFields::elements($document, 'declarant') as $declarant) {
            $value = XmlFields::field($declarant, 'siret');
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private static function generatedAt(DOMDocument $document, WireDate $dates, EnvoiVocabulary $vocabulary): ?Instant
    {
        foreach ($vocabulary->dates() as $name) {
            $value = XmlFields::firstValue($document, $name);
            if ($value !== null) {
                return $dates->parse($value);
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function reasons(DOMDocument $document, EnvoiVocabulary $vocabulary): array
    {
        $reasons = [];
        foreach ($vocabulary->reasons() as $name) {
            foreach (XmlFields::elements($document, $name) as $element) {
                $text = XmlFields::clean($element->textContent);
                if ($text !== null) {
                    $reasons[] = $text;
                }
            }
        }

        return $reasons;
    }
}
