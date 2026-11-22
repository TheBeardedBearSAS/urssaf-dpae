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
use Throwable;

/**
 * Where the outbox relay delivers the integration events (a message bus, an event dispatcher...).
 */
interface IntegrationEventSink
{
    /**
     * @throws Throwable when the event could not be delivered: it stays in the outbox and is relayed again later
     */
    public function dispatch(IntegrationEvent $event): void;
}
