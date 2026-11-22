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

use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

final readonly class PurgeOutboxHandler
{
    public function __construct(
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    /**
     * @return int the number of deleted entries
     */
    public function __invoke(PurgeOutbox $command): int
    {
        return $this->outbox->purgeDispatchedBefore(Instant::fromDateTime($this->clock->now()->sub($command->retention)));
    }
}
