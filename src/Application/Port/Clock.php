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

use DateTimeImmutable;

/**
 * Current time. `Infrastructure\Clock\SystemClock` implements it and PSR-20 at once, `PsrClock` adapts any PSR-20 clock
 * (the application layer depends on the Domain and Contract only, ADR 0000 section 1).
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
