<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Lock;

use LogicException;
use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;

/**
 * A store whose locks are all held by someone else, and that fails when asked to wait for them.
 */
final class BusyStore implements BlockingStoreInterface
{
    #[Override]
    public function save(Key $key): void
    {
        throw new LockConflictedException();
    }

    #[Override]
    public function waitAndSave(Key $key): void
    {
        throw new LogicException('The operation must not wait for the lock.');
    }

    #[Override]
    public function delete(Key $key): void {}

    #[Override]
    public function exists(Key $key): bool
    {
        return false;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void {}
}
