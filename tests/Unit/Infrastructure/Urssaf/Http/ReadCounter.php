<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

/**
 * Counts the `read()` calls of a stream and the bytes they returned.
 */
final class ReadCounter
{
    private int $bytes = 0;
    private int $reads = 0;

    public function record(int $bytes): void
    {
        ++$this->reads;
        $this->bytes += $bytes;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function reads(): int
    {
        return $this->reads;
    }
}
