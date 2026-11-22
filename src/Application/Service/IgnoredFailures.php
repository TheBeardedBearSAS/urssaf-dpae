<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use Throwable;

/**
 * Reports nothing: the default of a host that wires no reporter.
 */
final readonly class IgnoredFailures implements FailureReporter
{
    public function eventPublicationFailed(Throwable $failure, array $events): void {}
}
