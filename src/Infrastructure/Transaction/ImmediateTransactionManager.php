<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Transaction;

use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;

/**
 * Runs the operation at once, with no transaction: for the in-memory repositories, which have nothing to roll back.
 */
final readonly class ImmediateTransactionManager implements TransactionManager
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
