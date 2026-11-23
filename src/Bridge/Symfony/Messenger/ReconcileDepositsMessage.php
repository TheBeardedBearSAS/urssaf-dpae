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
 * Asks to reconcile the deposits of unknown outcome of one tenant.
 */
final readonly class ReconcileDepositsMessage
{
    public function __construct(public string $tenantId) {}
}
