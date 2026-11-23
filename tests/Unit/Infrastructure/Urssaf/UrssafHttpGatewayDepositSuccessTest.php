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
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Fixtures;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class UrssafHttpGatewayDepositSuccessTest extends TestCase
{
    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function deposit(HttpStack $stack): DepositResult
    {
        return $stack->gateway()->deposit($stack->tenant(), Fixtures::flow());
    }

    public function testAnElectronicReceiptIsReturnedWithItsRawDocument(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml; charset=ISO-8859-1']);

        $result = self::deposit($stack);

        static::assertInstanceOf(ElectronicReceipt::class, $result);
        static::assertSame('FICTIF0000000000000001', $result->flowId()?->value());
        static::assertSame(Fixtures::file('envoi_receipt.xml'), $result->document()->bytes());
        static::assertSame('application/xml; charset=ISO-8859-1', $result->document()->contentType());
    }

    public function testTheFlowIsSentGzippedAsIsToTheDepositHost(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        self::deposit($stack);

        $requests = $stack->requests();
        static::assertCount(2, $requests);
        $call = $requests[1];
        static::assertSame('POST', $call->getMethod());
        static::assertSame('https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0', (string) $call->getUri());
        static::assertSame('text/plain', $call->getHeaderLine('Content-Type'));
        static::assertSame('gzip', $call->getHeaderLine('Content-Encoding'));
        static::assertSame(Fixtures::flow()->bytes(), gzdecode((string) $call->getBody()));
        static::assertSame('DPAELogin jeton=' . HttpStack::JETON, $call->getHeaderLine('Authorization'));
    }

    public function testAConcentratorDepositsOnTheDepositHostWithThePortAndAuthenticatesWithout(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        self::deposit($stack);

        $requests = $stack->requests();
        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $requests[0]->getUri());
        static::assertSame('https://depot.dpae-edi.urssaf.fr:8443/deposer-dsn/1.0', (string) $requests[1]->getUri());
    }

    public function testTheHostsAreOverridable(): void
    {
        $endpoints = ApiConfiguration::defaults()->endpoints()
            ->withBaseUri(DpaeService::Authentication, 'http://mock.test:1080/auth')
            ->withBaseUri(DpaeService::Deposit, 'http://mock.test:1080/deposit/');
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withEndpoints($endpoints), ServiceCode::concentrator());
        $stack->queueToken();
        $stack->queue(200, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        self::deposit($stack);

        $requests = $stack->requests();
        static::assertSame('http://mock.test:1080/auth/authentifier_dpae', (string) $requests[0]->getUri());
        static::assertSame('http://mock.test:1080/deposit/deposer-dsn/1.0', (string) $requests[1]->getUri());
    }

    public function testAnotherSuccessStatusIsStillAReceipt(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(201, Fixtures::file('envoi_receipt.xml'), ['Content-Type' => 'application/xml']);

        static::assertInstanceOf(ElectronicReceipt::class, self::deposit($stack));
    }

    public function testARejectionNoticeIsAnAnswerNotAnError(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(422, Fixtures::file('envoi_rejection.xml'), ['Content-Type' => 'application/xml']);

        $result = self::deposit($stack);

        static::assertInstanceOf(RejectionNotice::class, $result);
        static::assertSame('FICTIF0000000000000002', $result->flowId()?->value());
        static::assertSame(Fixtures::file('envoi_rejection.xml'), $result->document()->bytes());
    }

    public function testAGzippedAnswerIsDecoded(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, (string) gzencode(Fixtures::file('envoi_receipt.xml')), ['Content-Type' => 'application/xml', 'Content-Encoding' => 'gzip']);

        $result = self::deposit($stack);

        static::assertInstanceOf(ElectronicReceipt::class, $result);
        static::assertSame(Fixtures::file('envoi_receipt.xml'), $result->document()->bytes());
    }
}
