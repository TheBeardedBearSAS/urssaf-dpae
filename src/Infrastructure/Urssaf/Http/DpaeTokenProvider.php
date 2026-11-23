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
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafAuthenticationFailed;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafEndpoints;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\CredentialsXmlWriter;
use Throwable;

/**
 * Gets the opaque DPAE token with a `POST` of the XML `identifiants` and keeps it in the {@see TokenStore}, per tenant
 * (ADR 0001). The token has no published lifetime, so it is never expired proactively: the {@see XmlApiClient} drops it
 * with {@see self::invalidate()} when URSSAF refuses it. Nothing secret (password, token) is ever logged.
 *
 * @internal
 */
final readonly class DpaeTokenProvider
{
    private const KEY_PREFIX = 'urssaf_dpae.token.';

    public function __construct(
        private ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private StreamFactoryInterface $streamFactory,
        private TokenStore $tokenStore,
        private ClockInterface $clock,
        private ApiConfiguration $configuration,
        private CredentialsXmlWriter $writer,
        private LoggerInterface $logger,
    ) {}

    /**
     * Returns the cached token, authenticating first when there is none.
     *
     * @param Operation $operation the call the token is for (named by the exceptions, never sent)
     *
     * @throws UrssafAuthenticationFailed 401: credentials refused (the realm tells which)
     * @throws UrssafRequestRejected      422: the identity does not meet the Net-Entreprises format constraints
     * @throws UrssafUnavailable          transport error, 429 or 5xx: the call itself was not sent, so
     *                                    {@see UrssafUnavailable::mayHaveReachedUrssaf()} is false
     * @throws UnexpectedUrssafResponse   any other status, an HTML page or an unusable token
     * @throws InvalidValue               `authentication.invalid_character` when a credential cannot be written in XML
     */
    public function token(TenantId $tenantId, DeclarantCredentials $credentials, Operation $operation): DpaeToken
    {
        $key = $this->cacheKey($tenantId, $credentials);
        $cached = $this->tokenStore->get($key);
        if ($cached instanceof DpaeToken) {
            return $cached;
        }

        $token = $this->fetch($credentials, $operation);
        $this->tokenStore->save($key, $token);

        return $token;
    }

    public function invalidate(TenantId $tenantId, DeclarantCredentials $credentials): void
    {
        $this->tokenStore->delete($this->cacheKey($tenantId, $credentials));
    }

    /**
     * Opaque key, never containing the password: tenant, authentication endpoint, SIRET and service code.
     */
    private function cacheKey(TenantId $tenantId, DeclarantCredentials $credentials): string
    {
        return self::KEY_PREFIX . hash('sha256', \sprintf(
            '%s|%s|%s|%d',
            $tenantId->value(),
            $this->configuration->endpoints()->baseUri(DpaeService::Authentication, $credentials->service()),
            $credentials->siret()->value(),
            $credentials->service()->value(),
        ));
    }

    private function fetch(DeclarantCredentials $credentials, Operation $operation): DpaeToken
    {
        $uri = $this->configuration->endpoints()->uri(
            DpaeService::Authentication,
            $credentials->service(),
            UrssafEndpoints::AUTHENTICATION_PATH,
        );
        $request = $this->requestFactory
            ->createRequest('POST', $uri)
            ->withHeader('Content-Type', 'application/xml')
            ->withHeader('Accept-Encoding', 'gzip')
            ->withHeader('User-Agent', ApiConfiguration::USER_AGENT)
            ->withBody($this->streamFactory->createStream($this->writer->write($credentials)));

        $startedAt = $this->clock->now();

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (Throwable $exception) {
            (new CallLog($this->logger, $this->clock))->write('warning', 'URSSAF token request failed: transport error.', $startedAt, [
                'exception' => $exception::class,
            ]);

            throw UrssafUnavailable::whileAuthenticating($operation, null, null, $exception);
        }

        $answer = HttpAnswer::fromResponse($response, $this->configuration->maxResponseBytes());
        (new CallLog($this->logger, $this->clock))->write('info', 'URSSAF token request.', $startedAt, ['status' => $answer->status()]);

        return $this->tokenFrom($answer, $operation);
    }

    private function tokenFrom(HttpAnswer $answer, Operation $operation): DpaeToken
    {
        $status = $answer->status();

        if ($answer->isSuccess()) {
            return $this->parse($answer);
        }

        if ($status === 401) {
            throw UrssafAuthenticationFailed::because($answer->authenticationFailure(), $answer->realm());
        }

        if ($status === 422) {
            throw UrssafRequestRejected::because(Operation::Authenticate, $status, 'authentication.invalid_parameters');
        }

        if ($status === 429 || $status >= 500) {
            throw UrssafUnavailable::whileAuthenticating(
                $operation,
                $status,
                $answer->retryAfterSeconds($this->clock->now()),
                null,
            );
        }

        throw UnexpectedUrssafResponse::unexpectedStatus(Operation::Authenticate, $status);
    }

    private function parse(HttpAnswer $answer): DpaeToken
    {
        if ($answer->isHtml()) {
            throw UnexpectedUrssafResponse::invalidPayload('the token answer is an HTML page');
        }

        try {
            return DpaeToken::fromResponseBody($answer->body());
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy('the token is empty or holds control characters', $exception);
        }
    }
}
