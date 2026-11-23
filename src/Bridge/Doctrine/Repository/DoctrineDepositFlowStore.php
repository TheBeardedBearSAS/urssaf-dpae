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
use RuntimeException;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity\DepositFlowRecord;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;

/**
 * The deposited flow of a deposit, gzip-compressed, for a host that must keep an exact copy (ADR 0008, `keep_flows`). Not
 * a port: the package keeps no flow by itself, the fingerprint and the result document are enough to reconcile. The flow
 * is the most sensitive data of the package (personal data, NIR): see "Personal data" in docs/doctrine.md.
 */
final readonly class DoctrineDepositFlowStore
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function keep(DepositId $depositId, string $flow): void
    {
        (new Upsert($this->connection(), $this->table(), 'deposit_id'))->write($depositId->value(), DepositFlowRecord::of($depositId, $flow)->row(), DepositFlowRecord::TYPES);
    }

    /**
     * Erases the kept flow of the deposit (GDPR erasure, retention); returns whether there was one. The deposit stays.
     */
    public function delete(DepositId $depositId): bool
    {
        $erased = $this->connection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE deposit_id = :id', $this->table()),
            ['id' => $depositId->value()],
        );

        return !\in_array($erased, [0, '0'], true);
    }

    /**
     * @throws RuntimeException the stored bytes are not a gzip stream
     */
    public function find(DepositId $depositId): ?string
    {
        $row = $this->connection()->fetchAssociative(
            \sprintf('SELECT flow FROM %s WHERE deposit_id = :id', $this->table()),
            ['id' => $depositId->value()],
        );
        if ($row === false) {
            return null;
        }
        $flow = gzdecode((new Row($this->connection(), $row))->bytes('flow'));

        return $flow === false ? throw new RuntimeException('The stored flow is not a gzip stream.') : $flow;
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private function table(): string
    {
        return $this->entityManager->getClassMetadata(DepositFlowRecord::class)->getTableName();
    }
}
