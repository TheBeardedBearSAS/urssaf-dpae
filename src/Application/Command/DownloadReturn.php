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

use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Downloads the document of a tracked return (listed beforehand by a synchronisation or a listing).
 */
final readonly class DownloadReturn
{
    public function __construct(
        public TenantId $tenantId,
        public ReturnIdentity $identity,
    ) {}
}
