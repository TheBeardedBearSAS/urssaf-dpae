<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity;

use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FlowIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Row of `urssaf_dpae_returns`, one per `(tenant, flux.id, nature, ops)` (ADR 0006). Mapped for the schema tools,
 * written and read with DBAL by {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnRepository}.
 *
 * The primary key is derived from the identity ({@see self::key()}): the aggregate has no identifier of its own. An
 * absent `ops` is stored as an empty string with `hasOps` false, so that it stays different from an empty one (as in
 * memory) while the unique index never sees a `NULL`.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class ReturnRecord
{
    /** DBAL types of the columns of {@see self::row()} (the others are plain strings). */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'flow_id' => FlowIdType::NAME,
        'nature' => 'integer',
        'has_ops' => 'boolean',
        'publication_at' => InstantType::NAME,
        'production_at' => InstantType::NAME,
        'deposit_id' => DepositIdType::NAME,
        'first_seen_at' => InstantType::NAME,
        'updated_at' => InstantType::NAME,
        'url' => 'text',
    ];

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per column of the row of a return: the limit (12) applies to the constructors of aggregates, this one only holds the columns
     */
    private function __construct(
        protected string $id,
        private readonly TenantId $tenantId,
        private readonly FlowId $flowId,
        private readonly int $nature,
        private readonly bool $hasOps,
        private readonly string $ops,
        private readonly string $status,
        private readonly string $publicationRaw,
        private readonly ?Instant $publicationAt,
        private readonly string $productionRaw,
        private readonly ?Instant $productionAt,
        private readonly ?string $returnId,
        private readonly ?string $delegate,
        private readonly ?string $url,
        private readonly ?DepositId $depositId,
        private readonly DownloadState $downloadState,
        private readonly Instant $firstSeenAt,
        private readonly Instant $updatedAt,
    ) {}

    public static function of(TrackedReturn $return): self
    {
        $reference = $return->reference();

        return new self(
            self::key($return->tenantId(), $return->identity()),
            $return->tenantId(),
            $reference->flowId(),
            $reference->natureCode(),
            $reference->ops() !== null,
            $reference->ops() ?? '',
            $reference->statusCode(),
            $reference->publicationRaw(),
            $reference->publishedAt(),
            $reference->productionRaw(),
            $reference->producedAt(),
            $reference->returnId(),
            $reference->delegate(),
            $reference->url(),
            $return->depositId(),
            $return->downloadState(),
            $return->firstSeenAt(),
            $return->updatedAt(),
        );
    }

    /**
     * The primary key of the row.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The primary key of the return of the tenant with this identity: a SHA-256 of the identity, in which a missing `ops`
     * differs from an empty one. Also the key of the stored content ({@see ReturnContentRecord}).
     */
    public static function key(TenantId $tenantId, ReturnIdentity $identity): string
    {
        return hash('sha256', json_encode([$tenantId->value(), $identity->flowId()->value(), $identity->natureCode(), $identity->ops()], \JSON_THROW_ON_ERROR));
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'flow_id' => $this->flowId,
            'nature' => $this->nature,
            'has_ops' => $this->hasOps,
            'ops' => $this->ops,
            'status' => $this->status,
            'publication_raw' => $this->publicationRaw,
            'publication_at' => $this->publicationAt,
            'production_raw' => $this->productionRaw,
            'production_at' => $this->productionAt,
            'return_id' => $this->returnId,
            'delegate' => $this->delegate,
            'url' => $this->url,
            'deposit_id' => $this->depositId,
            'download_state' => $this->downloadState->value,
            'first_seen_at' => $this->firstSeenAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
