<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port;

use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;

/**
 * An event waiting in the outbox, with its position and the number of failed relay attempts.
 */
final readonly class OutboxEntry
{
    public function __construct(
        public int $sequence,
        public IntegrationEvent $event,
        public int $attempts,
    ) {}
}
