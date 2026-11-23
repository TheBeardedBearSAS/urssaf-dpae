<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Serializer;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * The answer of URSSAF to a deposit as a JSON document: its kind (`receipt` or `rejection`), the flow id, the declarant
 * SIRET and the generation instant when it carried them, the reject reasons and the raw document (base 64, because the
 * bytes keep the encoding the answer was read in). Personal data: see "Personal data" in docs/doctrine.md.
 */
final class DepositResultSerializer
{
    private const RECEIPT = 'receipt';
    private const REJECTION = 'rejection';
    private const SUBJECT = 'deposit_result';

    /**
     * @return array<string, mixed>
     */
    public static function toArray(DepositResult $result): array
    {
        $document = $result->document();

        return [
            'kind' => $result instanceof RejectionNotice ? self::REJECTION : self::RECEIPT,
            'flowId' => $result->flowId()?->value(),
            'siret' => $result->declarantSiret()?->value(),
            'generatedAt' => $result->generatedAt()?->toIso8601(),
            'contentType' => $document->contentType(),
            'document' => base64_encode($document->bytes()),
            'reasons' => $result instanceof RejectionNotice ? $result->reasons() : [],
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws InvalidValue
     */
    public static function fromArray(array $data): DepositResult
    {
        $flowId = self::optional($data, 'flowId');
        $siret = self::optional($data, 'siret');
        $generatedAt = self::optional($data, 'generatedAt');
        $flow = $flowId === null ? null : FlowId::fromString($flowId);
        $declarant = $siret === null ? null : Siret::fromString($siret);
        $generated = $generatedAt === null ? null : Instant::fromIso8601($generatedAt);
        $document = RawDocument::of(self::bytes(self::required($data, 'document')), self::required($data, 'contentType'));

        return match (self::required($data, 'kind')) {
            self::RECEIPT => ElectronicReceipt::of($flow, $declarant, $generated, $document),
            self::REJECTION => RejectionNotice::of($flow, $declarant, $generated, $document, self::reasons($data)),
            default => throw InvalidValue::invalidFormat(self::SUBJECT, 'kind receipt or rejection'),
        };
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function required(array $data, string $key): string
    {
        // @mago-expect analysis:mixed-assignment The decoded JSON is untyped: the check below narrows it.
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : throw InvalidValue::invalidFormat(self::SUBJECT, $key . ' as a string');
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optional(array $data, string $key): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::required($data, $key);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private static function reasons(array $data): array
    {
        // @mago-expect analysis:mixed-assignment The decoded JSON is untyped: the checks below narrow it.
        $reasons = $data['reasons'] ?? null;
        if (!\is_array($reasons) || !array_is_list($reasons)) {
            throw InvalidValue::invalidFormat(self::SUBJECT, 'reasons as a list of strings');
        }

        return array_map(static fn(mixed $reason): string => \is_string($reason) ? $reason : throw InvalidValue::invalidFormat(self::SUBJECT, 'reasons as a list of strings'), $reasons);
    }

    private static function bytes(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);

        return $bytes === false ? throw InvalidValue::invalidFormat(self::SUBJECT, 'document in base 64') : $bytes;
    }
}
