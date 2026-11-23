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
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnContentRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * {@see ReturnContentStore} on the DBAL connection of an entity manager: the downloaded document of a return, kept after
 * URSSAF purged it (3 months, ADR 0008), under the key of the return ({@see ReturnRecord::key()}), with the instant it was
 * stored at (the clock of the package). Personal data: see "Personal data" in docs/doctrine.md.
 */
final readonly class DoctrineReturnContentStore implements ReturnContentStore
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Clock $clock,
    ) {}

    public function find(TenantId $tenantId, ReturnIdentity $identity): ?ReturnContent
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT content, content_type FROM %s WHERE return_id = :id', $this->table()),
            ['id' => ReturnRecord::key($tenantId, $identity)],
        );
        if ($row === false) {
            return null;
        }
        $columns = new Row($this->connection(), $row);

        return ReturnContent::of($columns->bytes('content'), $columns->string('content_type'));
    }

    public function save(TenantId $tenantId, ReturnIdentity $identity, ReturnContent $content): void
    {
        $record = ReturnContentRecord::of($tenantId, $identity, $content, Instant::fromDateTime($this->clock->now()));
        (new Upsert($this->connection(), $this->table(), 'return_id'))->write($record->returnId(), $record->row(), ReturnContentRecord::TYPES);
    }

    /**
     * Forgets the tenant's contents stored before the instant; returns how many. The return itself stays. The retention
     * period is the host's (ADR 0008): the package deletes nothing on its own.
     */
    public function deleteDownloadedBefore(TenantId $tenantId, Instant $before): int
    {
        return (int) $this->connection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE tenant_id = :tenant AND downloaded_at < :before', $this->table()),
            ['tenant' => $tenantId->value(), 'before' => $before],
            ['before' => InstantType::NAME],
        );
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(ReturnContentRecord::class)->getTableName();
    }
}
