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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnContentRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\ReturnRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FlowIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\DownloadState;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * {@see ReturnRepository} on the DBAL connection of an entity manager: one row per `(tenant, flux.id, nature, ops)`,
 * inserted or updated under its derived key ({@see ReturnRecord::key()}), kept beyond the URSSAF purge (ADR 0008).
 *
 * The aggregate is rebuilt through its own named constructor and transitions, from the row: a return is never stored
 * in a state it could not reach. There is no version: the writers of a return are the listing and the download, which
 * both save the whole row, except that a copy not `downloaded` never overwrites a `downloaded` row (the update is
 * conditional on `download_state`: the refreshed listing of that copy is dropped, the next synchronisation lists it again),
 * and a second writer inserting the same identity at the same time gets the driver's unique
 * violation, which the next run of the synchronisation does not meet again.
 */
final readonly class DoctrineReturnRepository implements ReturnRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function find(TenantId $tenantId, ReturnIdentity $identity): ?TrackedReturn
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE id = :id', $this->table()),
            ['id' => ReturnRecord::key($tenantId, $identity)],
        );

        return $row === false ? null : $this->hydrate(new Row($this->connection(), $row));
    }

    public function save(TrackedReturn $return): void
    {
        $record = ReturnRecord::of($return);
        // A synchronisation that listed the return before a download finished holds a copy that is still `pending`: it
        // must not bring a `downloaded` row back (the stored document would be orphaned). A download is saved as is.
        $unless = $return->downloadState() === DownloadState::Downloaded ? '' : \sprintf("download_state = '%s'", DownloadState::Downloaded->value);
        (new Upsert($this->connection(), $this->table(), 'id'))->write($record->id(), $record->row(), ReturnRecord::TYPES, $unless);
    }

    /**
     * Forgets the tenant's returns first seen before the instant, with their stored contents; returns how many returns.
     * The retention period is the host's (ADR 0008): the package deletes nothing on its own.
     */
    public function deleteSeenBefore(TenantId $tenantId, Instant $before): int
    {
        $seen = 'tenant_id = :tenant AND first_seen_at < :before';
        $parameters = ['tenant' => $tenantId->value(), 'before' => $before];
        $types = ['before' => InstantType::NAME];

        return $this->connection()->transactional(function (Connection $connection) use ($seen, $parameters, $types): int {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE return_id IN (SELECT id FROM %s WHERE %s)', $this->contentsTable(), $this->table(), $seen),
                $parameters,
                $types,
            );

            return (int) $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', $this->table(), $seen), $parameters, $types);
        });
    }

    private function hydrate(Row $row): TrackedReturn
    {
        $tenantId = $row->object('tenant_id', TenantIdType::NAME, TenantId::class);
        $reference = ReturnReference::fromListing(
            $row->object('flow_id', FlowIdType::NAME, FlowId::class),
            $row->int('nature'),
            $row->string('status'),
            $row->string('publication_raw'),
            $row->string('production_raw'),
            $row->nullableObject('publication_at', InstantType::NAME, Instant::class),
            $row->nullableObject('production_at', InstantType::NAME, Instant::class),
            $row->nullableString('return_id'),
            $row->bool('has_ops') ? $row->string('ops') : null,
            $row->nullableString('delegate'),
            $row->nullableString('url'),
        );
        $tracked = TrackedReturn::discover(
            $tenantId,
            $reference,
            $row->nullableObject('deposit_id', DepositIdType::NAME, DepositId::class),
            $row->object('first_seen_at', InstantType::NAME, Instant::class),
        );
        $updatedAt = $row->object('updated_at', InstantType::NAME, Instant::class);
        match (DownloadState::from($row->string('download_state'))) {
            DownloadState::Pending => $tracked->refresh($reference, $updatedAt),
            DownloadState::Downloaded => $tracked->markDownloaded($updatedAt),
            DownloadState::NotDownloadable => $tracked->markNotDownloadable($updatedAt),
            DownloadState::Purged => $tracked->markPurged($updatedAt),
        };
        $tracked->releaseEvents();

        return $tracked;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(ReturnRecord::class)->getTableName();
    }

    private function contentsTable(): string
    {
        return $this->entityManager->getClassMetadata(ReturnContentRecord::class)->getTableName();
    }
}
