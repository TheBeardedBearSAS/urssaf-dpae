<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger;

/**
 * Asks to synchronise the returns of one tenant from its cursor.
 */
final readonly class SynchronizeReturnsMessage
{
    public function __construct(public string $tenantId) {}
}
