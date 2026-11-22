<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use LogicException;
use Override;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * The in-memory lock store of the tests, which fails when someone asks to wait for a held lock: an operation of the package
 * never waits (a blocking acquisition on a store that cannot block would loop forever).
 */
final readonly class NonWaitingStore implements BlockingStoreInterface
{
    public function __construct(private InMemoryStore $store) {}

    #[Override]
    public function save(Key $key): void
    {
        $this->store->save($key);
    }

    #[Override]
    public function waitAndSave(Key $key): void
    {
        throw new LogicException('The operation must not wait for a lock.');
    }

    #[Override]
    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
