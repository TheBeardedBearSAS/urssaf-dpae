<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Accusé d'enregistrement électronique (AEE): the deposit was recorded (HTTP 200).
 *
 * The document may contain personal data: `__debugInfo()` shows its size and SHA-256 only, and the object cannot be
 * serialized.
 */
final readonly class ElectronicReceipt implements DepositResult
{
    use ForbidsSerialization;

    private function __construct(
        private ?FlowId $flowId,
        private ?Siret $declarantSiret,
        private ?Instant $generatedAt,
        private RawDocument $document,
    ) {}

    public static function of(
        ?FlowId $flowId,
        ?Siret $declarantSiret,
        ?Instant $generatedAt,
        RawDocument $document,
    ): self {
        return new self($flowId, $declarantSiret, $generatedAt, $document);
    }

    public function flowId(): ?FlowId
    {
        return $this->flowId;
    }

    public function declarantSiret(): ?Siret
    {
        return $this->declarantSiret;
    }

    public function generatedAt(): ?Instant
    {
        return $this->generatedAt;
    }

    public function document(): RawDocument
    {
        return $this->document;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'flowId' => $this->flowId?->value(),
            'generatedAt' => $this->generatedAt?->toIso8601(),
            'document' => $this->document->__debugInfo(),
        ];
    }
}
