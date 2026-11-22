<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Text;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\RetryDelays;

final class RetryDelaysTest extends TestCase
{
    public function testTheDelayOfARefusedPollingIsKept(): void
    {
        static::assertSame(30, RetryDelays::seconds(30));
        static::assertSame(0, RetryDelays::seconds(0));
    }

    public function testARefusedPollingWithoutDelayIsABug(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A refused polling carries the delay to wait.');

        RetryDelays::seconds(null);
    }
}
