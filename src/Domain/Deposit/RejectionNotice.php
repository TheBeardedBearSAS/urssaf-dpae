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
 * Avis de rejet: the deposit was rejected, nothing is recorded (HTTP 422 with an XML body).
 *
 * The document may contain personal data: `__debugInfo()` shows its size and SHA-256 only, and the object cannot be
 * serialized.
 */
final readonly class RejectionNotice implements DepositResult
{
    use ForbidsSerialization;

    /**
     * @param list<string> $reasons
     */
    private function __construct(
        private ?FlowId $flowId,
        private ?Siret $declarantSiret,
        private ?Instant $generatedAt,
        private RawDocument $document,
        private array $reasons,
    ) {}

    /**
     * @param list<string> $reasons the raw reject reasons as found (possibly none)
     */
    public static function of(
        ?FlowId $flowId,
        ?Siret $declarantSiret,
        ?Instant $generatedAt,
        RawDocument $document,
        array $reasons,
    ): self {
        return new self($flowId, $declarantSiret, $generatedAt, $document, $reasons);
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
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
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
            'reasonCount' => \count($this->reasons),
        ];
    }
}
