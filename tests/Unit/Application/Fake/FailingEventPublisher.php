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

use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;

final class FailingEventPublisher implements EventPublisher
{
    public function publish(DomainEvent ...$events): void
    {
        throw new RuntimeException('A listener failed.');
    }
}
