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
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayDepositModeTest extends TestCase
{
    public function testARealFlowIsRefusedByATestInstallationBeforeAnyCall(): void
    {
        foreach ([ApiConfiguration::defaults(), ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Test)] as $configuration) {
            $stack = HttpStack::standard($configuration, ServiceCode::direct());

            $exception = Thrown::by(static fn(): DepositResult => $stack->gateway()->deposit($stack->tenant(), Fixtures::realFlow()));

            static::assertInstanceOf(InvariantViolation::class, $exception);
            static::assertSame('declaration_mode.not_allowed', $exception->violationCode());
            static::assertSame(['mode' => 'real', 'installation_mode' => $configuration->declarationMode()->value], $exception->params());
            static::assertSame([], $stack->requests());
        }
    }

    public function testARealFlowIsDepositedByARealInstallation(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Real), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, $stack->gateway()->deposit($stack->tenant(), Fixtures::realFlow()));
    }

    public function testATestFlowIsDepositedByARealInstallation(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withDeclarationMode(DeclarationMode::Real), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, $stack->gateway()->deposit($stack->tenant(), Fixtures::flow()));
    }
}
