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
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;

final class XmlApiClientRequestTest extends TestCase
{
    private const URI = 'https://consultation.dpae-edi.urssaf.fr/lister-retours-declarant/1.0/20220130083000';

    private static function stack(): HttpStack
    {
        return HttpStack::standard(ApiConfiguration::defaults(), ServiceCode::direct());
    }

    private static function read(): ApiRequest
    {
        return ApiRequest::get(Operation::ListReturns, self::URI);
    }

    private static function tokenKey(): string
    {
        return 'urssaf_dpae.token.' . hash('sha256', 'acme|https://mon.urssaf.fr|99999999999999|25');
    }

    public function testAReadAuthenticatesThenSendsTheTokenAndAcceptsGzip(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<retours/>', ['Content-Type' => 'application/xml']);

        $answer = Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read());

        static::assertSame(200, $answer->status());
        static::assertSame('<retours/>', $answer->body());
        $requests = $stack->requests();
        static::assertCount(2, $requests);
        static::assertSame('POST', $requests[0]->getMethod());
        $call = $requests[1];
        static::assertSame('GET', $call->getMethod());
        static::assertSame(self::URI, (string) $call->getUri());
        static::assertSame('DPAELogin jeton=' . HttpStack::JETON, $call->getHeaderLine('Authorization'));
        static::assertSame('gzip', $call->getHeaderLine('Accept-Encoding'));
        static::assertSame('thebeardedbear-urssaf-dpae', $call->getHeaderLine('User-Agent'));
        static::assertFalse($call->hasHeader('Content-Type'));
        static::assertFalse($call->hasHeader('Content-Encoding'));
        static::assertSame('', (string) $call->getBody());
    }

    public function testADepositIsGzippedOnceWithTheConfiguredContentType(): void
    {
        $flow = "<?xml version=\"1.0\" encoding=\"ISO-8859-1\"?>\n<FR_DUE_Upload>\xE9</FR_DUE_Upload>";
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withDepositContentType('application/xml'), ServiceCode::direct());
        $stack->queueToken();
        $stack->queue(200, '<envoi/>', []);

        Wiring::client($stack)->send($stack->tenant(), $stack->credentials, ApiRequest::postGzip(
            Operation::Deposit,
            'https://depot.dpae-edi.urssaf.fr/deposer-dsn/1.0',
            $flow,
            'application/xml',
        ));

        $call = $stack->requests()[1];
        static::assertSame('POST', $call->getMethod());
        static::assertSame('application/xml', $call->getHeaderLine('Content-Type'));
        static::assertSame('gzip', $call->getHeaderLine('Content-Encoding'));
        static::assertSame('gzip', $call->getHeaderLine('Accept-Encoding'));
        static::assertSame('DPAELogin jeton=' . HttpStack::JETON, $call->getHeaderLine('Authorization'));
        $body = (string) $call->getBody();
        static::assertSame("\x1F\x8B", substr($body, 0, 2));
        static::assertSame($flow, gzdecode($body));
    }

    public function testTheTokenIsAuthenticatedOnceForSeveralCalls(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, 'a', []);
        $stack->queue(200, 'b', []);
        $client = Wiring::client($stack);

        $client->send($stack->tenant(), $stack->credentials, self::read());
        $client->send($stack->tenant(), $stack->credentials, self::read());

        static::assertCount(3, $stack->requests());
    }

    public function testTheResponseSizeLimitIsHandedToTheAnswer(): void
    {
        $stack = HttpStack::standard(ApiConfiguration::defaults()->withMaxResponseBytes(3), ServiceCode::direct());
        $stack->tokens->save(self::tokenKey(), DpaeToken::fromResponseBody(HttpStack::JETON));
        $stack->queue(200, 'abcd', []);

        $answer = Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read());

        $exception = Thrown::by($answer->body(...));
        static::assertInstanceOf(UnexpectedUrssafResponse::class, $exception);
    }

    public function testItLogsEachCallWithoutSecretsNorBodies(): void
    {
        $stack = self::stack();
        $stack->queueToken();
        $stack->queue(200, '<retours><secret>NIR 1700000000000</secret></retours>', []);

        Wiring::client($stack)->send($stack->tenant(), $stack->credentials, self::read());

        $records = $stack->logger->records();
        static::assertCount(2, $records);
        static::assertSame(
            ['level' => 'info', 'message' => 'URSSAF call.', 'context' => ['operation' => 'list_returns', 'status' => 200, 'duration_ms' => 5]],
            $records[1],
        );
        $dump = $stack->logger->dump();
        static::assertStringNotContainsString(HttpStack::JETON, $dump);
        static::assertStringNotContainsString(HttpStack::MOTDEPASSE, $dump);
        static::assertStringNotContainsString('1700000000000', $dump);
        static::assertStringNotContainsString('lister-retours', $dump);
    }
}
