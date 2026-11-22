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

use TheBeardedBear\UrssafDpae\Infrastructure\Id\RandomBytesSource;

/**
 * Always answers the same bytes and remembers how many were asked for.
 */
final class RecordingRandomBytes implements RandomBytesSource
{
    private int $asked = 0;

    public function __construct(private readonly string $bytes) {}

    public function bytes(int $length): string
    {
        $this->asked = $length;

        return $this->bytes;
    }

    public function asked(): int
    {
        return $this->asked;
    }
}
