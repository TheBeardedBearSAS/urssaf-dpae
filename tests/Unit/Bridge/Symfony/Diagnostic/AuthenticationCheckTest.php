<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Diagnostic;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\GatewayFactory;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\AuthenticationCheck;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckContext;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckResult;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckStatus;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class AuthenticationCheckTest extends TestCase
{
    public function testACachedTokenNeverHidesRefusedCredentials(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $stack->queue(200, HttpStack::JETON, ['Content-Type' => 'application/octet-stream']);
        $stack->queue(401, '', ['Content-Type' => 'text/html']);
        $provider = GatewayFactory::probeTokenProvider(new HttpTransport($stack->client, $stack->factory, $stack->factory), $stack->clock, $stack->configuration, null);
        $provider->token($stack->tenant(), $stack->credentials, Operation::Authenticate);
        $check = new AuthenticationCheck(new InMemoryCredentialsProvider([HttpStack::TENANT => $stack->credentials]), $provider);

        $results = $check->run(CheckContext::withRemoteCalls([$stack->tenant()]));

        static::assertCount(1, $results);
        static::assertSame(CheckStatus::Failure, $results[0]->status);
        static::assertSame('authentication', $results[0]->check);
        static::assertSame(HttpStack::TENANT, $results[0]->subject);
        static::assertCount(2, $stack->mock()->getRequests());
    }

    public function testNothingIsSentWithoutRemoteCalls(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
        $provider = GatewayFactory::probeTokenProvider(new HttpTransport($stack->client, $stack->factory, $stack->factory), $stack->clock, $stack->configuration, null);
        $check = new AuthenticationCheck(new InMemoryCredentialsProvider([HttpStack::TENANT => $stack->credentials]), $provider);

        $results = $check->run(CheckContext::withoutRemoteCalls([$stack->tenant()]));

        static::assertSame([CheckStatus::Skipped], array_map(static fn(CheckResult $result): CheckStatus => $result->status, $results));
        static::assertSame([], $stack->mock()->getRequests());
    }
}
