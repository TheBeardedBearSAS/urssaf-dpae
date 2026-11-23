<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port\Urssaf;

use RuntimeException;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;

/**
 * HTTP 404 on a download: the return is unknown or was purged (returns are kept 3 months, api-reference.md section
 * 1.2). Not retryable: the return is marked purged.
 */
final class ReturnNoLongerAvailable extends RuntimeException implements UrssafException
{
    private function __construct(private readonly ReturnIdentity $identity)
    {
        parent::__construct('The URSSAF return is unknown or no longer available (HTTP 404).', 404);
    }

    public static function forReturn(ReturnReference $return): self
    {
        return new self($return->identity());
    }

    public function identity(): ReturnIdentity
    {
        return $this->identity;
    }
}
