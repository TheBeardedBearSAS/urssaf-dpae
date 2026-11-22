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

/**
 * Delivers the pending integration events of the outbox, in recording order.
 */
final readonly class RelayOutbox
{
    /**
     * @param positive-int $limit events read at most
     */
    public function __construct(public int $limit) {}
}
