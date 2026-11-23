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

/**
 * Source of unique identifiers for the local aggregates (deposit ids).
 */
interface IdGenerator
{
    /**
     * A new, unique, non-blank identifier of at most 64 printable characters.
     *
     * @return non-empty-string
     */
    public function generate(): string;
}
