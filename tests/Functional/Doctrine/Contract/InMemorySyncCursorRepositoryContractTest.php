<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;

final class InMemorySyncCursorRepositoryContractTest extends TestCase
{
    use SyncCursorRepositoryContract;

    protected function createCursors(): SyncCursorRepository
    {
        return new InMemorySyncCursorRepository();
    }
}
