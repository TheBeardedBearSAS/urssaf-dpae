<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use DateInterval;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Service\Instants;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class InstantsTest extends TestCase
{
    public function testItAddsACalendarInterval(): void
    {
        static::assertSame('2022-02-01T10:05:00Z', Instants::plus(Samples::at('2022-02-01T10:00:00Z'), new DateInterval('PT5M'))->toIso8601());
        static::assertSame('2022-04-30T00:00:00Z', Instants::plus(Samples::at('2022-01-30T00:00:00Z'), new DateInterval('P3M'))->toIso8601());
    }

    public function testItSubtractsACalendarInterval(): void
    {
        static::assertSame('2022-02-01T09:55:00Z', Instants::minus(Samples::at('2022-02-01T10:00:00Z'), new DateInterval('PT5M'))->toIso8601());
        static::assertSame('2021-01-01T00:00:00Z', Instants::minus(Samples::at('2022-02-01T00:00:00Z'), new DateInterval('P13M'))->toIso8601());
    }
}
