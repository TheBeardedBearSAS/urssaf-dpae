<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Dto;

/**
 * Outcome of one `SynchronizeReturns` run.
 */
final readonly class SynchronizationReport
{
    public function __construct(
        public int $discovered,
        public int $windows,
        public bool $refused,
        public bool $needsAttention,
        public ?int $retryAfterSeconds,
    ) {}

    public static function completed(int $discovered, int $windows): self
    {
        return new self($discovered, $windows, false, false, null);
    }

    /**
     * URSSAF refused the listing (422 / 429): the cursor did not move, retry after `$retryAfterSeconds`.
     */
    public static function refused(int $discovered, int $windows, bool $needsAttention, int $retryAfterSeconds): self
    {
        return new self($discovered, $windows, true, $needsAttention, $retryAfterSeconds);
    }
}
