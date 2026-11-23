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

use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\BytesType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Row of `urssaf_dpae_return_contents`: the downloaded document of a return, kept after URSSAF purged it (ADR 0008),
 * 1:1 with the return under the same key ({@see ReturnRecord::key()}). Mapped for the schema tools, written and read with
 * DBAL by {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineReturnContentStore}. No foreign key: the
 * content is stored before the return is saved. Personal data.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class ReturnContentRecord
{
    /** DBAL types of the columns of {@see self::row()} (the others are plain strings). */
    public const TYPES = [
        'tenant_id' => TenantIdType::NAME,
        'content' => BytesType::NAME,
        'size' => 'integer',
        'downloaded_at' => InstantType::NAME,
    ];

    private function __construct(
        protected string $returnId,
        private readonly TenantId $tenantId,
        private readonly string $contentType,
        private readonly string $content,
        private readonly int $size,
        private readonly string $sha256,
        private readonly Instant $downloadedAt,
    ) {}

    public static function of(TenantId $tenantId, ReturnIdentity $identity, ReturnContent $content, Instant $downloadedAt): self
    {
        return new self(
            ReturnRecord::key($tenantId, $identity),
            $tenantId,
            $content->contentType(),
            $content->bytes(),
            $content->size(),
            hash('sha256', $content->bytes()),
            $downloadedAt,
        );
    }

    /**
     * The primary key of the row: the key of the return.
     */
    public function returnId(): string
    {
        return $this->returnId;
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return [
            'return_id' => $this->returnId,
            'tenant_id' => $this->tenantId,
            'content_type' => $this->contentType,
            'content' => $this->content,
            'size' => $this->size,
            'sha256' => $this->sha256,
            'downloaded_at' => $this->downloadedAt,
        ];
    }
}
