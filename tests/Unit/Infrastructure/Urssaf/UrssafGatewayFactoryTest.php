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

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\Thrown;

final class UrssafGatewayFactoryTest extends TestCase
{
    public function testItBuildsAGatewayWithTheDefaultOptions(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);
        $factory = new Psr17Factory();

        $gateway = UrssafGatewayFactory::create(
            new HttpTransport($stack->client, $factory, $factory),
            new InMemoryCredentialsProvider([HttpStack::TENANT => $stack->credentials]),
            new InMemoryTokenStore(),
            ApiConfiguration::defaults(),
        );

        static::assertSame([], $gateway->listReturns($stack->tenant(), Instant::fromIso8601('2022-01-30T07:30:00Z'), null));
        static::assertCount(2, $stack->requests());
    }

    public function testTheOptionsAreHonoured(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        $stack->gateway()->listReturns($stack->tenant(), Instant::fromIso8601('2022-01-30T07:30:00Z'), null);

        $messages = array_column($stack->logger->records(), 'message');
        static::assertSame(['URSSAF token request.', 'URSSAF call.'], $messages);
        static::assertSame(5, $stack->logger->records()[1]['context']['duration_ms']);
    }

    public function testTheAuthenticationEncodingIsCheckedWhenTheGatewayIsBuilt(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withAuthenticationEncoding('NOT-AN-ENCODING'), ServiceCode::direct());

        $exception = Thrown::by($stack->gateway(...));

        static::assertInstanceOf(InvalidValue::class, $exception);
    }

    public function testTheAuthenticationEncodingIsUsedForTheCredentials(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withAuthenticationEncoding('ISO-8859-1'), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        $stack->gateway()->listReturns($stack->tenant(), Instant::fromIso8601('2022-01-30T07:30:00Z'), null);

        static::assertStringStartsWith('<?xml version="1.0" encoding="ISO-8859-1"?>', (string) $stack->requests()[0]->getBody());
    }
}
