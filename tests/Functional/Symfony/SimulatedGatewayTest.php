<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

/**
 * "gateway.simulated: true": the whole workflow runs against the in-memory URSSAF, and never in the real mode (ADR 0003).
 */
final class SimulatedGatewayTest extends TestCase
{
    public function testTheHttpGatewayIsUsedByDefault(): void
    {
        $harness = Harness::bootDefault([]);

        static::assertInstanceOf(UrssafHttpGateway::class, $harness->privateService(DpaeGateway::class, DpaeGateway::class));
        static::assertFalse($harness->has(SimulatedDpaeGateway::class));
    }

    public function testTheSimulatorReplacesTheHttpGatewayAndIsPublic(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));
        $simulator = $harness->get(SimulatedDpaeGateway::class);

        static::assertSame($simulator, $harness->privateService(DpaeGateway::class, DpaeGateway::class));
        $deposit = $harness->get(DepositDpaeHandler::class)(new DepositDpae(
            TenantId::fromString('tenant-1'),
            ExternalReference::fromString('ref-1'),
            DpaeFlow::fromXml(Flows::xml(DeclarationMode::Test, 'a'), DeclarationMode::Test, TestIndicators::defaults()),
        ));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertCount(1, $simulator->deposited());
        static::assertSame([], $harness->client->getRequests());
    }

    public function testAnEnvironmentVariableThatResolvesToTheRealModeIsRefusedWhenTheSimulatorIsCreated(): void
    {
        $_ENV['URSSAF_DPAE_TEST_MODE'] = 'real';
        $_SERVER['URSSAF_DPAE_TEST_MODE'] = 'real';
        try {
            $harness = Harness::bootDefault(Simulated::config(['declaration_mode' => '%env(URSSAF_DPAE_TEST_MODE)%']));

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('requires the "test" declaration mode');

            $harness->get(SimulatedDpaeGateway::class);
        } finally {
            unset($_ENV['URSSAF_DPAE_TEST_MODE'], $_SERVER['URSSAF_DPAE_TEST_MODE']);
        }
    }

    public function testAnEnvironmentVariableThatResolvesToTheTestModeBuildsTheSimulator(): void
    {
        $_ENV['URSSAF_DPAE_TEST_MODE'] = 'test';
        $_SERVER['URSSAF_DPAE_TEST_MODE'] = 'test';
        try {
            $harness = Harness::bootDefault(Simulated::config(['declaration_mode' => '%env(URSSAF_DPAE_TEST_MODE)%']));

            static::assertInstanceOf(SimulatedDpaeGateway::class, $harness->get(SimulatedDpaeGateway::class));
        } finally {
            unset($_ENV['URSSAF_DPAE_TEST_MODE'], $_SERVER['URSSAF_DPAE_TEST_MODE']);
        }
    }
}
