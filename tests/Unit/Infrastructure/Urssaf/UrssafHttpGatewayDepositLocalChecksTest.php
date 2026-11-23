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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafHttpGatewayDepositLocalChecksTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    public function testAFlowOverTheLocalLimitIsRefusedBeforeAnyCall(): void
    {
        $size = \strlen(Fixtures::flow()->bytes());
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withMaxFlowBytes($size - 1), ServiceCode::direct());

        $exception = Thrown::by(static fn(): DepositResult => self::deposit($stack));

        static::assertInstanceOf(InvalidValue::class, $exception);
        static::assertSame('flow.too_large', $exception->violationCode());
        static::assertSame([], $stack->requests());
    }

    public function testAFlowAtTheLocalLimitIsSent(): void
    {
        $size = \strlen(Fixtures::flow()->bytes());
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withMaxFlowBytes($size), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, self::deposit($stack));
    }

    public function testTheLocalLimitCanBeDisabled(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withMaxFlowBytes(null), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, self::deposit($stack));
    }

    public function testAnUnknownTenantSendsNothing(): void
    {
        $stack = self::stack();

        $exception = Thrown::by(static fn(): DepositResult => $stack->gateway()->deposit(TenantId::fromString('nobody'), Fixtures::flow()));

        static::assertInstanceOf(RecordNotFound::class, $exception);
        static::assertSame([], $stack->requests());
    }

    public function testTheLogsHoldNoFlowNorAnswer(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        self::deposit($stack);

        $dump = $stack->logger->dump();
        static::assertStringNotContainsString('FICTIF', $dump);
        static::assertStringNotContainsString('TEST', $dump);
        static::assertStringNotContainsString(HttpStack::JETON, $dump);
        static::assertStringNotContainsString(HttpStack::MOTDEPASSE, $dump);
    }
}
