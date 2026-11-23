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
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;
use TheBeardedBear\UrssafDpae\Domain\Exception\DomainException;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;

/**
 * What a lost race on a unique index of `urssaf_dpae_deposits` means for the save of a deposit, once the database has
 * refused it (the driver does not name the index portably, so the rows are asked): another open deposit of the tenant with
 * the reference or the fingerprint is a `deposit.reference_conflict` / `deposit.flow_already_open` (the reference held by
 * an open deposit of the same fingerprint is the same deposit asked twice: `deposit.concurrent_modification`); the id or the flow
 * id already stored is a `deposit.concurrent_modification` (reload and decide again).
 */
final readonly class DepositConflicts
{
    public function __construct(
        private Connection $connection,
        private string $table,
    ) {}

    /**
     * Whether a deposit is stored under the id.
     */
    public function stored(DepositId $id): bool
    {
        return $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE id = :id', $this->table),
            ['id' => $id->value()],
        ) !== false;
    }

    /**
     * The refusal of the first save of the deposit, if the rows explain it.
     */
    public function ofInsert(Deposit $deposit): ?DomainException
    {
        return $this->stored($deposit->id()) ? ConcurrentModification::of('deposit', $deposit->id()->value()) : $this->ofUpdate($deposit);
    }

    /**
     * The refusal of a later save of the deposit, if the rows explain it.
     */
    public function ofUpdate(Deposit $deposit): ?DomainException
    {
        $open = $deposit->isOpen();
        $flowId = $deposit->flowId();

        return match (true) {
            $open && ($referenceConflict = $this->ofReference($deposit)) instanceof DomainException => $referenceConflict,
            $open && $this->heldByAnother($deposit, 'open_fingerprint', $deposit->fingerprint()->value())
                => InvariantViolation::because('deposit.flow_already_open', 'The flow belongs to an open deposit under another reference.'),
            $flowId instanceof FlowId && $this->heldByAnother($deposit, 'flow_id', $flowId->value())
                => ConcurrentModification::of('deposit', $deposit->id()->value()),
            default => null,
        };
    }

    /**
     * The open deposit that holds the reference: with the same fingerprint it is the very deposit that was asked for twice
     * (a lost race, retryable: reload and the reference finds it), with another one the reference is really taken.
     */
    private function ofReference(Deposit $deposit): ?DomainException
    {
        $holder = $this->connection->fetchOne(
            \sprintf('SELECT open_fingerprint FROM %s WHERE tenant_id = :tenant AND open_reference = :value AND id <> :id', $this->table),
            ['tenant' => $deposit->tenantId()->value(), 'value' => $deposit->externalReference()->value(), 'id' => $deposit->id()->value()],
        );

        return match (true) {
            $holder === false => null,
            $holder === $deposit->fingerprint()->value() => ConcurrentModification::of('deposit', $deposit->id()->value()),
            default => InvariantViolation::because('deposit.reference_conflict', 'The reference belongs to an open deposit of another flow.'),
        };
    }

    private function heldByAnother(Deposit $deposit, string $column, string $value): bool
    {
        return $this->connection->fetchOne(
            \sprintf('SELECT 1 FROM %s WHERE tenant_id = :tenant AND %s = :value AND id <> :id', $this->table, $column),
            ['tenant' => $deposit->tenantId()->value(), 'value' => $value, 'id' => $deposit->id()->value()],
        ) !== false;
    }
}
