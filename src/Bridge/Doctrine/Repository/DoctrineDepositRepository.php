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

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\DepositFlowRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\DepositRecord;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * {@see DepositRepository} on the DBAL connection of an entity manager, with the semantics of the in-memory one (ADR 0008).
 *
 * No unit of work: every read builds a new aggregate from the row and every write is one conditional statement, so that
 * the optimistic lock and the claim hold across processes and a failed save leaves the entity manager open.
 *
 * - {@see self::save()} inserts the first version of a deposit and afterwards updates `WHERE id = :id AND version = :read`;
 *   no row updated is `deposit.concurrent_modification`. The aggregate moves to the version it was stored at.
 * - {@see self::claim()} is one `UPDATE … WHERE state = 'pending' AND attempts < :max`.
 * - The open reference and fingerprint are unique per tenant by two derived columns, `NULL` once the deposit is closed.
 *   A lost race on them is reported as `deposit.reference_conflict` / `deposit.flow_already_open`; a lost race on the id
 *   or on the flow id as `deposit.concurrent_modification` (reload and decide again).
 *
 * @mago-expect lint:too-many-methods The eight queries of the port, the retention purge, the erasures and the private helpers of the statements they share
 */
final readonly class DoctrineDepositRepository implements DepositRepository
{
    private const SENT_WITHOUT_FLOW_ID = [
        DepositState::Depositing,
        DepositState::OutcomeUnknown,
        DepositState::Received,
        DepositState::Rejected,
    ];

    /**
     * The states a deposit does not leave (ADR 0005).
     */
    private const FINISHED = [
        DepositState::Received,
        DepositState::Rejected,
        DepositState::Failed,
        DepositState::NotReceived,
    ];

    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(Deposit $deposit): void
    {
        $read = $deposit->version();
        $row = DepositRecord::of($deposit, $read + 1)->row();

        try {
            // Its own (nested) transaction: a refused statement aborts the transaction on some platforms, and the rows are read
            // again below to explain the refusal.
            $stored = $this->connection()->transactional(
                fn(Connection $connection): int|string => $read === 0
                    ? $connection->insert($this->table(), $row, DepositRecord::TYPES)
                    : $connection->update($this->table(), $row, ['id' => $deposit->id(), 'version' => $read], DepositRecord::TYPES),
            );
        } catch (UniqueConstraintViolationException $violation) {
            $conflicts = $this->conflicts();

            throw ($read === 0 ? $conflicts->ofInsert($deposit) : $conflicts->ofUpdate($deposit)) ?? $violation;
        }
        if ($stored === 0) {
            throw ConcurrentModification::of('deposit', $deposit->id()->value());
        }
        $deposit->advanceVersion();
    }

    public function get(DepositId $id): Deposit
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE id = :id', $this->table()),
            ['id' => $id->value()],
        );

        return $row === false ? throw RecordNotFound::withIdentifier('deposit', $id->value()) : $this->hydrate($row);
    }

    public function findOpenByReference(TenantId $tenantId, ExternalReference $reference): ?Deposit
    {
        return $this->findOne('open_reference', $tenantId, $reference->value());
    }

    public function findOpenByFingerprint(TenantId $tenantId, FlowFingerprint $fingerprint): ?Deposit
    {
        return $this->findOne('open_fingerprint', $tenantId, $fingerprint->value());
    }

    public function findByFlowId(TenantId $tenantId, FlowId $flowId): ?Deposit
    {
        return $this->findOne('flow_id', $tenantId, $flowId->value());
    }

    public function claim(DepositId $id, Instant $now, int $maxAttempts): bool
    {
        $claimed = $this->connection()->executeStatement(
            \sprintf(
                'UPDATE %s SET state = :depositing, attempts = attempts + 1, claimed_at = :now, updated_at = :now, version = version + 1'
                . ' WHERE id = :id AND state = :pending AND attempts < :max',
                $this->table(),
            ),
            ['depositing' => DepositState::Depositing->value, 'now' => $now, 'id' => $id->value(), 'pending' => DepositState::Pending->value, 'max' => $maxAttempts],
            ['now' => InstantType::NAME],
        );
        if ($claimed === 1) {
            return true;
        }
        if (!$this->conflicts()->stored($id)) {
            throw RecordNotFound::withIdentifier('deposit', $id->value());
        }

        return false;
    }

    public function withoutFlowId(TenantId $tenantId, int $limit): array
    {
        $query = $this->connection()->createQueryBuilder()
            ->select('*')
            ->from($this->table())
            ->where('tenant_id = :tenant', 'flow_id IS NULL', 'state IN (:states)')
            ->setParameter('tenant', $tenantId->value())
            ->setParameter('states', self::values(self::SENT_WITHOUT_FLOW_ID), ArrayParameterType::STRING);

        return $this->oldestClaims($query, $limit);
    }

    public function staleDepositing(TenantId $tenantId, Instant $claimedBefore, int $limit): array
    {
        $query = $this->connection()->createQueryBuilder()
            ->select('*')
            ->from($this->table())
            ->where('tenant_id = :tenant', 'state = :depositing', 'claimed_at < :before')
            ->setParameter('tenant', $tenantId->value())
            ->setParameter('depositing', DepositState::Depositing->value)
            ->setParameter('before', $claimedBefore, InstantType::NAME);

        return $this->oldestClaims($query, $limit);
    }

    /**
     * Forgets the tenant's deposits that reached a final answer (`received`, `rejected`, `failed`, `not_received`) and were
     * last updated before the instant, with the flow kept for them if any; returns how many. A deposit that is forgotten no
     * longer guards its reference and its flow against a second deposit: the retention period is the host's (ADR 0008).
     */
    public function deleteFinishedBefore(TenantId $tenantId, Instant $before): int
    {
        $finished = 'tenant_id = :tenant AND state IN (:states) AND updated_at < :before';
        $parameters = ['tenant' => $tenantId->value(), 'states' => self::values(self::FINISHED), 'before' => $before];
        $types = ['states' => ArrayParameterType::STRING, 'before' => InstantType::NAME];

        return $this->connection()->transactional(function (Connection $connection) use ($finished, $parameters, $types): int {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE deposit_id IN (SELECT id FROM %s WHERE %s)', $this->flowsTable(), $this->table(), $finished),
                $parameters,
                $types,
            );

            return (int) $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', $this->table(), $finished), $parameters, $types);
        });
    }

    /**
     * Erases the deposit, with its kept flow, whatever its state (GDPR erasure of one deposit); returns whether it existed. The
     * returns of the same flow are not touched: see "Erasure" in docs/doctrine.md.
     */
    public function delete(DepositId $id): bool
    {
        return $this->erase('id = :value', ['value' => $id->value()]) > 0;
    }

    /**
     * Erases every deposit of the tenant with the external reference (one per past attempt), with their kept flows, whatever
     * their state (GDPR erasure by reference); returns how many.
     */
    public function deleteByReference(TenantId $tenantId, ExternalReference $reference): int
    {
        return $this->erase('tenant_id = :tenant AND external_reference = :value', ['tenant' => $tenantId->value(), 'value' => $reference->value()]);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function erase(string $condition, array $parameters): int
    {
        return $this->connection()->transactional(function (Connection $connection) use ($condition, $parameters): int {
            $connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE deposit_id IN (SELECT id FROM %s WHERE %s)', $this->flowsTable(), $this->table(), $condition),
                $parameters,
            );

            return (int) $connection->executeStatement(\sprintf('DELETE FROM %s WHERE %s', $this->table(), $condition), $parameters);
        });
    }

    /**
     * @return list<Deposit>
     */
    private function oldestClaims(QueryBuilder $query, int $limit): array
    {
        $rows = $query
            ->orderBy('claimed_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    private function findOne(string $column, TenantId $tenantId, string $value): ?Deposit
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE tenant_id = :tenant AND %s = :value', $this->table(), $column),
            ['tenant' => $tenantId->value(), 'value' => $value],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Deposit
    {
        return DepositHydrator::hydrate(new Row($this->connection(), $row));
    }

    /**
     * @param list<DepositState> $states
     *
     * @return list<string>
     */
    private static function values(array $states): array
    {
        return array_map(static fn(DepositState $state): string => $state->value, $states);
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(DepositRecord::class)->getTableName();
    }

    private function conflicts(): DepositConflicts
    {
        return new DepositConflicts($this->connection(), $this->table());
    }

    private function flowsTable(): string
    {
        return $this->entityManager->getClassMetadata(DepositFlowRecord::class)->getTableName();
    }
}
