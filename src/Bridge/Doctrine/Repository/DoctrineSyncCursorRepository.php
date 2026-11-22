<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\SyncCursorRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * {@see SyncCursorRepository} on the DBAL connection of an entity manager: one row per tenant (ADR 0006).
 */
final readonly class DoctrineSyncCursorRepository implements SyncCursorRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(TenantId $tenantId): ?SyncCursor
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE tenant_id = :tenant', $this->table()),
            ['tenant' => $tenantId->value()],
        );
        if ($row === false) {
            return null;
        }
        $columns = new Row($this->connection(), $row);

        return SyncCursor::of(
            $tenantId,
            $columns->nullableObject('cursor_at', InstantType::NAME, Instant::class),
            $columns->nullableObject('last_success_at', InstantType::NAME, Instant::class),
            $columns->int('consecutive_refusals'),
        );
    }

    public function save(SyncCursor $cursor): void
    {
        (new Upsert($this->connection(), $this->table(), 'tenant_id'))->write($cursor->tenantId()->value(), SyncCursorRecord::of($cursor)->row(), SyncCursorRecord::TYPES);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(SyncCursorRecord::class)->getTableName();
    }
}
