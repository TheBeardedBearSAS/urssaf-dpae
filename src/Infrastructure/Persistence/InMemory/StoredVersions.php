<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory;

use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Exception\ConcurrentModification;

/**
 * The version each deposit was last stored at, apart from the aggregate: the in-memory repository keeps the aggregates
 * themselves, so a copy read earlier is told from the stored one only by this record (ADR 0008).
 */
final class StoredVersions
{
    /**
     * @var array<string, int>
     */
    private array $versions = [];

    /**
     * @throws ConcurrentModification `deposit.concurrent_modification`: the stored version is not the deposit's
     */
    public function assertCurrent(Deposit $deposit): void
    {
        $key = $deposit->id()->value();
        if (\array_key_exists($key, $this->versions) && $this->versions[$key] !== $deposit->version()) {
            throw ConcurrentModification::of('deposit', $key);
        }
    }

    /**
     * The deposit was stored: its version, and the stored one, move on.
     */
    public function advance(Deposit $deposit): void
    {
        $deposit->advanceVersion();
        $this->versions[$deposit->id()->value()] = $deposit->version();
    }
}
