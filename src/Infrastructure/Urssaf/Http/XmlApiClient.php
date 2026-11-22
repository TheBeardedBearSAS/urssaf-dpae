<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use Throwable;

/**
 * Authenticated XML transport over PSR-18 (ADR 0001 and 0004). Build the gateway with
 * {@see \TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory}.
 *
 * Per call: `Authorization: DPAELogin jeton=…`, `Accept-Encoding: gzip`, and for the deposit a gzip body with
 * `Content-Encoding: gzip`. A 401 drops the cached token; only the realm `Expiration du jeton` authenticates again and
 * replays the call, once (a 401 is answered before processing, so the replay is safe even for the deposit). Every other
 * status is handed back to the gateway, which knows what it means for its operation. Neither the token nor a request or
 * response body is logged, and a failure of the PSR-18 client is always a transport failure.
 *
 * @internal
 */
final readonly class XmlApiClient
{
    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private DpaeTokenProvider $tokenProvider,
        private ClockInterface $clock,
        private ApiConfiguration $configuration,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws UrssafAuthenticationFailed a refused token (after the replay) or refused credentials
     * @throws UrssafRequestRejected      the token call answered 422
     * @throws UrssafUnavailable          the token call failed (the call was not sent), or the call itself hit a
     *                                    transport error (it may have reached URSSAF)
     * @throws UnexpectedUrssafResponse   the token call answered something unexpected
     */
    public function send(TenantId $tenantId, DeclarantCredentials $credentials, ApiRequest $request): HttpAnswer
    {
        $answer = $this->attempt($tenantId, $credentials, $request);
        if ($answer->status() !== 401) {
            return $answer;
        }

        $this->tokenProvider->invalidate($tenantId, $credentials);
        $failure = $answer->authenticationFailure();
        if ($failure !== AuthenticationFailure::TokenExpired) {
            throw UrssafAuthenticationFailed::because($failure, $answer->realm());
        }

        $answer = $this->attempt($tenantId, $credentials, $request);
        if ($answer->status() === 401) {
            $this->tokenProvider->invalidate($tenantId, $credentials);

            throw UrssafAuthenticationFailed::because($answer->authenticationFailure(), $answer->realm());
        }

        return $answer;
    }

    private function attempt(TenantId $tenantId, DeclarantCredentials $credentials, ApiRequest $request): HttpAnswer
    {
        $operation = $request->operation();
        $httpRequest = $this->buildRequest($request, $this->tokenProvider->token($tenantId, $credentials, $operation));
        $startedAt = $this->clock->now();

        try {
            $response = $this->httpClient->sendRequest($httpRequest);
        } catch (Throwable $exception) {
            (new CallLog($this->logger, $this->clock))->write('warning', 'URSSAF call failed: transport error.', $startedAt, [
                'operation' => $operation->value,
                'exception' => $exception::class,
            ]);

            throw UrssafUnavailable::dueToTransportError($operation, $exception);
        }

        $answer = HttpAnswer::fromResponse($response, $this->configuration->maxResponseBytes());
        (new CallLog($this->logger, $this->clock))->write('info', 'URSSAF call.', $startedAt, ['operation' => $operation->value, 'status' => $answer->status()]);

        return $answer;
    }

    private function buildRequest(ApiRequest $request, #[SensitiveParameter] DpaeToken $token): RequestInterface
    {
        $httpRequest = $this->requestFactory
            ->createRequest($request->method(), $request->uri())
            ->withHeader('Authorization', 'DPAELogin jeton=' . $token->reveal())
            ->withHeader('Accept-Encoding', 'gzip')
            ->withHeader('User-Agent', ApiConfiguration::USER_AGENT);

        $body = $request->body();
        if ($body === null) {
            return $httpRequest;
        }

        $compressed = gzencode($body);
        \assert($compressed !== false, 'gzencode() fails only on an invalid level.');

        return $httpRequest
            ->withHeader('Content-Type', $request->contentType())
            ->withHeader('Content-Encoding', 'gzip')
            ->withBody($this->streamFactory->createStream($compressed));
    }
}
