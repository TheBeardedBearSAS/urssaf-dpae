<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Standalone;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturns;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class UrssafDpaeBuilderTest extends TestCase
{
    use UrssafDpaeBuilderFixtures;

    public function testByDefaultTheHttpGatewayTalksToUrssaf(): void
    {
        $stack = self::stack(ApiConfiguration::defaults());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        $dpae = self::builder($stack)->build();
        $listed = $dpae->listReturns()(new ListReturns(Samples::tenant(HttpStack::TENANT), Samples::at('2022-01-30T07:30:00Z'), null));

        static::assertInstanceOf(UrssafHttpGateway::class, $dpae->gateway());
        static::assertSame([], $listed);
        static::assertCount(2, $stack->requests());
    }

    public function testTheHttpGatewayUsesTheGivenTokenStoreClockAndLogger(): void
    {
        $stack = self::stack(ApiConfiguration::defaults());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);
        $tokens = new InMemoryTokenStore();

        $dpae = self::builder($stack)->withTokenStore($tokens)->withLogger($stack->logger)->withClock($stack->clock)->build();
        $dpae->listReturns()(new ListReturns(Samples::tenant(HttpStack::TENANT), Samples::at('2022-01-30T07:30:00Z'), null));

        static::assertCount(2, $stack->logger->records());
        static::assertSame(5, $stack->logger->records()[1]['context']['duration_ms']);
        static::assertNotNull($tokens->get('urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25')));
        static::assertNull($stack->tokens->get('urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25')));
    }

    public function testAGivenGatewayIsUsedInATestInstallation(): void
    {
        $gateway = self::simulated();

        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))->withGateway($gateway)->build();

        static::assertSame($gateway, $dpae->gateway());
    }

    public function testAGivenGatewayIsRefusedInARealInstallation(): void
    {
        $builder = self::builder(self::stack(ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Real)));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('only the HTTP gateway may talk in a real installation');

        $builder->withGateway(self::simulated());
    }

    public function testTheRealHttpGatewayIsAllowedInARealInstallation(): void
    {
        $stack = self::stack(ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Real));

        $dpae = self::builder($stack)->build();

        static::assertInstanceOf(UrssafHttpGateway::class, $dpae->gateway());
    }

    public function testTheInstallationModeCapsEveryDeposit(): void
    {
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))->withGateway(self::simulated())->build();

        try {
            $dpae->depositDpae()(self::command(DeclarationMode::Real, 'a'));
            static::fail('InvariantViolation expected.');
        } catch (InvariantViolation $e) {
            static::assertSame('declaration_mode.not_allowed', $e->violationCode());
        }
    }

    public function testARealInstallationAcceptsARealFlowThroughTheHttpGatewayCeiling(): void
    {
        $stack = self::stack(ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Real));
        $stack->queueToken();
        $stack->queue(200, '<envoi><IdFluxDPAE>F1</IdFluxDPAE></envoi>', ['Content-Type' => 'application/xml']);

        $deposit = self::builder($stack)->build()->depositDpae()(self::command(DeclarationMode::Real, 'a'));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame(DeclarationMode::Real, $deposit->mode());
    }
}
