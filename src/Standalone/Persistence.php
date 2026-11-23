<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Standalone;

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredOutbox;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Transaction\ImmediateTransactionManager;

/**
 * The persistence ports of ADR 0008 as one set, because they must share one store and one transaction: the
 * repositories, the content store, the outbox (appended in the transaction of the state change) and the transaction
 * manager.
 */
final readonly class Persistence
{
    public function __construct(
        public DepositRepository $deposits,
        public ReturnRepository $returns,
        public SyncCursorRepository $cursors,
        public ReturnContentStore $contents,
        public Outbox $outbox,
        public TransactionManager $transactions,
    ) {}

    /**
     * Everything in memory, nothing durable, no outbox: enough to try the package (ADR 0008 asks durable repositories
     * for the full lifecycle).
     */
    public static function inMemory(): self
    {
        return new self(
            new InMemoryDepositRepository(),
            new InMemoryReturnRepository(),
            new InMemorySyncCursorRepository(),
            new InMemoryReturnContentStore(),
            new IgnoredOutbox(),
            new ImmediateTransactionManager(),
        );
    }
}
