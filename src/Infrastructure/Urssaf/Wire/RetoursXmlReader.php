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

use DOMElement;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

/**
 * Reads the answer of the list operations (schemas `retours` / `flux` / `retour`, api-reference sections 2.3 and 2.4).
 *
 * The XML form is not published (section 5.9): every field is accepted as a child element or as an attribute, under
 * any namespace, and unknown elements and attributes are ignored. Element names are the Swagger property names. A
 * value of a closed list that this version does not know (`nature`, `statut`) is kept raw and never breaks the read,
 * and so is a date that cannot be parsed (the raw string is kept, the instant is null).
 *
 * What cannot be represented is refused rather than skipped, because a silently lost return would be lost for good once
 * the polling cursor moves on: a root that is neither `retours` nor holds a `flux`, a `flux` that holds a `retour` but has no usable `id` (an empty `flux` is skipped), and a
 * `retour` without an integer `nature`, a `statut`, a `publication` or a `production` (all required by the Swagger).
 *
 * Only the references are returned: the raw XML of the answer is the business of the HTTP client (lot 4), which keeps
 * the body it received (the `RawDocument` of the deposit answers has no counterpart for a listing).
 */
final readonly class RetoursXmlReader
{
    private const INT32_MIN = -2_147_483_648;
    private const INT32_MAX = 2_147_483_647;

    /**
     * @param string $contentType the `Content-Type` header of the answer, an empty string when there is none
     *
     * @return list<ReturnReference>
     *
     * @throws UnexpectedUrssafResponse
     */
    public static function read(string $bytes, string $contentType, WireDate $dates): array
    {
        $document = SafeXmlReader::parse($bytes, $contentType);
        $flows = XmlFields::elements($document, 'flux');
        /** @var DOMElement $root A parsed document has a root element. */
        $root = $document->documentElement;
        if ($flows === [] && $root->localName !== 'retours') {
            throw UnexpectedUrssafResponse::invalidPayload('the list answer has no retours or flux element');
        }

        $references = [];
        foreach ($flows as $flow) {
            $returns = XmlFields::elements($flow, 'retour');
            if ($returns === []) {
                continue;
            }
            $flowId = self::flowId($flow);
            foreach ($returns as $return) {
                $references[] = self::reference($flowId, $return, $dates);
            }
        }

        return $references;
    }

    private static function flowId(DOMElement $flow): FlowId
    {
        $id = XmlFields::field($flow, 'id');
        if ($id === null) {
            throw UnexpectedUrssafResponse::invalidPayload('a flux has no id');
        }

        try {
            return FlowId::fromString($id);
        } catch (InvalidValue $error) {
            throw UnexpectedUrssafResponse::causedBy('a flux has an unusable id', $error);
        }
    }

    private static function reference(FlowId $flowId, DOMElement $return, WireDate $dates): ReturnReference
    {
        $publication = self::required($return, 'publication');
        $production = self::required($return, 'production');

        return ReturnReference::fromListing(
            $flowId,
            self::nature(self::required($return, 'nature')),
            self::required($return, 'statut'),
            $publication,
            $production,
            $dates->parse($publication),
            $dates->parse($production),
            XmlFields::field($return, 'id'),
            XmlFields::field($return, 'ops'),
            XmlFields::field($return, 'delegataire'),
            XmlFields::field($return, 'url'),
        );
    }

    private static function required(DOMElement $return, string $name): string
    {
        return XmlFields::field($return, $name) ?? throw UnexpectedUrssafResponse::invalidPayload(
            \sprintf('a retour has no %s', $name),
        );
    }

    private static function nature(string $value): int
    {
        $nature = (int) $value;
        if (preg_match('/^-?\d{1,10}\z/', $value) !== 1 || $nature < self::INT32_MIN || $nature > self::INT32_MAX) {
            throw UnexpectedUrssafResponse::invalidPayload('a retour has a nature that is not an integer');
        }

        return $nature;
    }
}
