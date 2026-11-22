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

use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Publishes domain events to in-process listeners, after the commit. A failing listener never undoes the work.
 */
interface EventPublisher
{
    public function publish(DomainEvent ...$events): void;
}
