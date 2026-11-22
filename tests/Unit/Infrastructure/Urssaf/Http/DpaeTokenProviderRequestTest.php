<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

final class DpaeTokenProviderRequestTest extends TestCase
{
    private const KEY = 'urssaf_dpae.token.';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    public function testItPostsTheCredentialsAsXmlAndReturnsTheToken(): void
    {
        $stack = self::stack();
        $stack->queueToken();

        $token = Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit);

        static::assertSame(HttpStack::JETON, $token->reveal());
        $requests = $stack->requests();
        static::assertCount(1, $requests);
        $request = $requests[0];
        static::assertSame('POST', $request->getMethod());
        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $request->getUri());
        static::assertSame('application/xml', $request->getHeaderLine('Content-Type'));
        static::assertSame('gzip', $request->getHeaderLine('Accept-Encoding'));
        static::assertSame('thebeardedbear-urssaf-dpae', $request->getHeaderLine('User-Agent'));
        static::assertSame('', $request->getHeaderLine('Authorization'));
        static::assertSame(IdentifiantsXmlWriter::utf8()->write($stack->credentials), (string) $request->getBody());
    }

    public function testTheTokenIsCachedPerTenantInTheStore(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $provider = Wiring::provider($stack);

        $first = $provider->token($stack->tenant(), $stack->credentials, Operation::Deposit);
        $second = $provider->token($stack->tenant(), $stack->credentials, Operation::ListReturns);

        static::assertSame($first, $second);
        static::assertCount(1, $stack->requests());
        $key = self::KEY . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25');
        static::assertSame($first, $stack->tokens->get($key));
    }

    public function testAnotherTenantAuthenticatesAgain(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queueToken();
        $provider = Wiring::provider($stack);

        $provider->token($stack->tenant(), $stack->credentials, Operation::Deposit);
        $provider->token(TenantId::fromString('other'), $stack->credentials, Operation::Deposit);

        static::assertCount(2, $stack->requests());
    }

    public function testTheKeyDependsOnTheAuthenticationEndpointAndTheService(): void
    {
        $concentrator = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::concentrator());
        $concentrator->queueToken();
        Wiring::provider($concentrator)->token($concentrator->tenant(), $concentrator->credentials, Operation::Deposit);

        static::assertNotNull($concentrator->tokens->get(self::KEY . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|98')));

        $overridden = HttpStack::standard(
            ApiConfiguration::defaults()->withEndpoints(
                ApiConfiguration::defaults()->endpoints()->withBaseUri(DpaeService::Authentication, 'http://mock.test:1080/base'),
            ),
            ServiceCode::direct(),
        );
        $overridden->queueToken();
        Wiring::provider($overridden)->token($overridden->tenant(), $overridden->credentials, Operation::Deposit);

        static::assertNotNull($overridden->tokens->get(self::KEY . hash('sha256', 'acme|http://mock.test:1080/base|99999999999999|25')));
        static::assertSame('http://mock.test:1080/base/authentifier_dpae', (string) $overridden->requests()[0]->getUri());
    }

    public function testTheConcentratorPortIsNeverAddedToTheAuthenticationUri(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::concentrator());
        $stack->queueToken();

        Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit);

        static::assertSame('https://mon.urssaf.fr/authentifier_dpae', (string) $stack->requests()[0]->getUri());
    }

    public function testInvalidateForgetsOnlyTheTokenOfTheTenant(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queueToken();
        $provider = Wiring::provider($stack);
        $other = TenantId::fromString('other');
        $provider->token($stack->tenant(), $stack->credentials, Operation::Deposit);
        $provider->token($other, $stack->credentials, Operation::Deposit);

        $provider->invalidate($stack->tenant(), $stack->credentials);

        static::assertNull($stack->tokens->get(self::KEY . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25')));
        static::assertNotNull($stack->tokens->get(self::KEY . hash('sha256', 'other|https://mon.urssaf.fr|99999999999999|25')));
    }

    public function testTheTokenIsTrimmedAndAGzipAnswerIsDecoded(): void
    {
        $stack = self::stack();
        $stack->queue(200, (string) gzencode(" dG9rZW4=\r\n"), ['Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'gzip']);

        $token = Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit);

        static::assertSame('dG9rZW4=', $token->reveal());
    }

    public function testItLogsTheCallWithoutAnySecret(): void
    {
        $stack = self::stack();
        $stack->queueToken();

        Wiring::provider($stack)->token($stack->tenant(), $stack->credentials, Operation::Deposit);

        static::assertSame(
            [['level' => 'info', 'message' => 'URSSAF token request.', 'context' => ['status' => 200, 'duration_ms' => 5]]],
            $stack->logger->records(),
        );
    }
}
