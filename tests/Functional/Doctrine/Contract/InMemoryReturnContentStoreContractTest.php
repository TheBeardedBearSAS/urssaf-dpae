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
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;

final class InMemoryReturnContentStoreContractTest extends TestCase
{
    use ReturnContentStoreContract;

    protected function createContents(): ReturnContentStore
    {
        return new InMemoryReturnContentStore();
    }
}
