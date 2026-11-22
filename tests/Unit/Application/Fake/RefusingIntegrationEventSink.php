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

use Override;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;

/**
 * A sink that is down.
 */
final class RefusingIntegrationEventSink implements IntegrationEventSink
{
    #[Override]
    public function dispatch(IntegrationEvent $event): never
    {
        throw new RuntimeException('The sink is down.');
    }
}
