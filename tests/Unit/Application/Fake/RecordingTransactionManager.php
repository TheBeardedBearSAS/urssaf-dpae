<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Application\Port\TransactionManager;
use Throwable;

final readonly class RecordingTransactionManager implements TransactionManager
{
    public function __construct(private Journal $journal) {}

    public function transactional(callable $operation): mixed
    {
        $this->journal->note('begin');

        try {
            $result = $operation();
        } catch (Throwable $failure) {
            $this->journal->note('rollback');

            throw $failure;
        }
        $this->journal->note('commit');

        return $result;
    }
}
