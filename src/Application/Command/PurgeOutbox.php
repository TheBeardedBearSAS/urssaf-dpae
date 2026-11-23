<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Command;

use DateInterval;

/**
 * Deletes the outbox entries dispatched for longer than the retention.
 */
final readonly class PurgeOutbox
{
    public function __construct(public DateInterval $retention) {}
}
