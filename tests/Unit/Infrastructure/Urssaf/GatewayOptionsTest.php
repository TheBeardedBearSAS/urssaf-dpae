<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\GatewayOptions;

final class GatewayOptionsTest extends TestCase
{
    public function testTheDefaultsGiveNothing(): void
    {
        $options = GatewayOptions::defaults();

        static::assertNull($options->clock());
        static::assertNull($options->logger());
    }

    public function testEachCollaboratorIsSetIndependently(): void
    {
        $clock = new SystemClock();
        $logger = new NullLogger();

        $withClock = GatewayOptions::defaults()->withClock($clock);
        $withLogger = GatewayOptions::defaults()->withLogger($logger);
        $both = $withClock->withLogger($logger);

        static::assertSame($clock, $withClock->clock());
        static::assertNull($withClock->logger());
        static::assertNull($withLogger->clock());
        static::assertSame($logger, $withLogger->logger());
        static::assertSame($clock, $both->clock());
        static::assertSame($logger, $both->logger());
        static::assertNull(GatewayOptions::defaults()->clock());
    }
}
