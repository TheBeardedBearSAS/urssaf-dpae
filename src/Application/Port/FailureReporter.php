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
use Throwable;

/**
 * Where the failures that must not interrupt a use case are reported (a listener that threw after the commit).
 */
interface FailureReporter
{
    /**
     * @param list<DomainEvent> $events the events of the unit of work whose publication failed
     */
    public function eventPublicationFailed(Throwable $failure, array $events): void;
}
