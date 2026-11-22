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

use Override;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * A lock store that always grants the lock, remembers the time to live asked for it and counts the releases.
 */
final class SpyStore implements PersistingStoreInterface
{
    /** @var list<float> */
    private array $ttls = [];

    private int $releases = 0;

    #[Override]
    public function save(Key $key): void {}

    #[Override]
    public function delete(Key $key): void
    {
        ++$this->releases;
    }

    #[Override]
    public function exists(Key $key): bool
    {
        return false;
    }

    #[Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->ttls[] = $ttl;
    }

    /**
     * @return list<float>
     */
    public function ttls(): array
    {
        return $this->ttls;
    }

    public function releases(): int
    {
        return $this->releases;
    }
}
