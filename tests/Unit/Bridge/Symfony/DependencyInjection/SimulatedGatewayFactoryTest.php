<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\DependencyInjection;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SequentialIdGenerator;

final class SimulatedGatewayFactoryTest extends TestCase
{
    public function testItBuildsTheSimulatorForTheTestMode(): void
    {
        $gateway = SimulatedGatewayFactory::create('test', new FixedClock(Samples::NOW), new SequentialIdGenerator());

        static::assertSame([], $gateway->calls());
    }

    #[DataProvider('refused')]
    public function testItRefusesTheRealModeAndAnythingThatIsNotAMode(string $mode): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires the "test" declaration mode');

        SimulatedGatewayFactory::create($mode, new FixedClock(Samples::NOW), new SequentialIdGenerator());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'real' => ['real'];
        yield 'an unresolved placeholder' => ['%env(URSSAF_DPAE_DECLARATION_MODE)%'];
        yield 'empty' => [''];
        yield 'upper case' => ['REAL'];
        yield 'production' => ['production'];
    }
}
