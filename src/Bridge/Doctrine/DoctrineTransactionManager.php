<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;

/**
 * {@see TransactionManager} on the DBAL connection of an entity manager.
 *
 * The repositories of this bridge write with DBAL and never touch the unit of work of the entity manager: nothing is
 * flushed, nothing is detached, and the entities of the host application are left alone. Inside
 * {@see self::transactional()} their statements are durable together when the operation returns, and rolled back when
 * it throws (the throwable is rethrown unchanged). Called inside a transaction opened by the host, the operation runs in
 * a nested transaction, a savepoint (DBAL 4 on a platform that supports them): a failing inner operation is undone to its
 * savepoint, and everything becomes durable only when the outermost transaction commits.
 */
final readonly class DoctrineTransactionManager implements TransactionManager
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    /**
     * @template T
     *
     * @param callable(): T $operation
     * @param-immediately-invoked-callable $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->getConnection()->transactional(static fn(): mixed => $operation());
    }
}
