<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Event;

use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

/**
 * Publishes nowhere: the default of a host that listens to no domain event.
 */
final readonly class NullEventPublisher implements EventPublisher
{
    public function publish(DomainEvent ...$events): void {}
}
