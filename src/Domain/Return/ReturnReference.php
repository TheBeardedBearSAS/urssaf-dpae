<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * One `retour` of a listed flow. Every value (nature, statut, ops, delegataire, id, raw dates) is kept as read, so that
 * an unknown value never breaks a read (ADR 0000 section 4, ADR 0006); the parsed instants are null when a date could
 * not be parsed.
 */
final readonly class ReturnReference
{
    private function __construct(
        private FlowId $flowId,
        private int $natureCode,
        private string $statusCode,
        private string $publicationRaw,
        private string $productionRaw,
        private ?Instant $publishedAt,
        private ?Instant $producedAt,
        private ?string $returnId,
        private ?string $ops,
        private ?string $delegate,
        private ?string $url,
    ) {
        if ($url !== null && trim($url) === '') {
            throw InvalidValue::empty('return_url');
        }
    }

    /**
     * Builds a reference from one listed `retour`. Every value is stored as read, whatever its length: URSSAF may widen
     * a list or add a code without breaking a read. The arguments are positional (the project forbids named arguments).
     *
     * @param ?string $returnId `retour.id` (published 1-10), for backward compatibility only; checked by {@see self::downloadId()}
     * @param ?string $ops      `retour.ops` (published 5-10), absent for a return by the SI DPAE
     * @param ?string $delegate `retour.delegataire` (published 5-9)
     * @param ?string $url      download URL, set only when a document is associated
     *
     * @throws InvalidValue `return_url.empty` when a url is present but blank
     *
     * @mago-expect lint:excessive-parameter-list One parameter per field of a listed `retour`: the constructor limit (12) applies to this named constructor, which only forwards them
     */
    public static function fromListing(
        FlowId $flowId,
        int $natureCode,
        string $statusCode,
        string $publicationRaw,
        string $productionRaw,
        ?Instant $publishedAt,
        ?Instant $producedAt,
        ?string $returnId,
        ?string $ops,
        ?string $delegate,
        ?string $url,
    ): self {
        return new self(
            $flowId,
            $natureCode,
            $statusCode,
            $publicationRaw,
            $productionRaw,
            $publishedAt,
            $producedAt,
            $returnId,
            $ops,
            $delegate,
            $url,
        );
    }

    public function flowId(): FlowId
    {
        return $this->flowId;
    }

    /**
     * Raw `nature`, always available.
     */
    public function natureCode(): int
    {
        return $this->natureCode;
    }

    /**
     * Null when the nature is unknown to this version of the package.
     */
    public function nature(): ?ReturnNature
    {
        return ReturnNature::fromCode($this->natureCode);
    }

    /**
     * Raw `statut`, always available.
     */
    public function statusCode(): string
    {
        return $this->statusCode;
    }

    /**
     * Null when the status is unknown to this version of the package.
     */
    public function status(): ?ReturnStatus
    {
        return ReturnStatus::fromCode($this->statusCode);
    }

    public function publicationRaw(): string
    {
        return $this->publicationRaw;
    }

    public function productionRaw(): string
    {
        return $this->productionRaw;
    }

    public function publishedAt(): ?Instant
    {
        return $this->publishedAt;
    }

    public function producedAt(): ?Instant
    {
        return $this->producedAt;
    }

    public function returnId(): ?string
    {
        return $this->returnId;
    }

    public function ops(): ?string
    {
        return $this->ops;
    }

    public function delegate(): ?string
    {
        return $this->delegate;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * True when URSSAF associates a document with this return (`url` or the legacy `id`).
     */
    public function hasDocument(): bool
    {
        return $this->url !== null || $this->returnId !== null;
    }

    /**
     * The `idRetour` of the download path, checked against the published 1-10 characters; null without a legacy `id`.
     *
     * @throws InvalidValue when the stored `id` is outside the bounds
     */
    public function downloadId(): ?ReturnId
    {
        return $this->returnId === null ? null : ReturnId::fromString($this->returnId);
    }

    /**
     * Identity of a return within a tenant: flow, nature and OPS.
     */
    public function identity(): ReturnIdentity
    {
        return new ReturnIdentity($this->flowId, $this->natureCode, $this->ops);
    }
}
