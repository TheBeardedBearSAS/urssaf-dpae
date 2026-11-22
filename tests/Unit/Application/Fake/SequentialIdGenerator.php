<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;

final class SequentialIdGenerator implements IdGenerator
{
    private int $last = 0;

    public function generate(): string
    {
        return 'id-' . ++$this->last;
    }
}
