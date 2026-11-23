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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;

final class InMemoryReturnRepositoryContractTest extends TestCase
{
    use ReturnRepositoryContract;

    protected function createReturns(): ReturnRepository
    {
        return new InMemoryReturnRepository();
    }
}
