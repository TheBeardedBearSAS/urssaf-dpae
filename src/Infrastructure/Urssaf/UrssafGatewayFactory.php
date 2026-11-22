<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf;

use Psr\Log\NullLogger;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\AnswerInterpreter;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\DpaeTokenProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\XmlApiClient;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

/**
 * Public entry point to build the URSSAF HTTP gateway outside a framework bridge:
 *
 *     $gateway = UrssafGatewayFactory::createWith(
 *         new HttpTransport($psr18Client, $psr17RequestFactory, $psr17StreamFactory),
 *         $credentialsProvider,
 *         new InMemoryTokenStore(),
 *         ApiConfiguration::defaults(),
 *         GatewayOptions::defaults()->withLogger($logger),
 *     );
 *
 * The PSR-18 client must NOT follow redirections (for example Symfony HttpClient `max_redirects => 0`): a 307 / 308
 * would resend the deposit `POST`, which the package never does on purpose (ADR 0004). A real deposit also needs
 * `ApiConfiguration::withDeclarationMode(DeclarationMode::Real)` (default `Test`, ADR 0003).
 *
 * {@see self::create()} takes the same arguments without the options. The token provider and the XML client it wires
 * are internal.
 */
final class UrssafGatewayFactory
{
    /**
     * Gateway with the default options ({@see GatewayOptions::defaults()}).
     *
     * @throws InvalidValue `authentication_encoding.unsupported` for an encoding ext-dom does not know
     */
    public static function create(
        HttpTransport $transport,
        CredentialsProvider $credentials,
        TokenStore $tokens,
        ApiConfiguration $configuration,
    ): UrssafHttpGateway {
        return self::createWith($transport, $credentials, $tokens, $configuration, GatewayOptions::defaults());
    }

    /**
     * @throws InvalidValue `authentication_encoding.unsupported` for an encoding ext-dom does not know
     */
    public static function createWith(
        HttpTransport $transport,
        CredentialsProvider $credentials,
        TokenStore $tokens,
        ApiConfiguration $configuration,
        GatewayOptions $options,
    ): UrssafHttpGateway {
        $clock = $options->clock() ?? new SystemClock();
        $logger = $options->logger() ?? new NullLogger();
        $tokenProvider = new DpaeTokenProvider(
            $transport->client(),
            $transport->requestFactory(),
            $transport->streamFactory(),
            $tokens,
            $clock,
            $configuration,
            IdentifiantsXmlWriter::withEncoding($configuration->authenticationEncoding()),
            $logger,
        );
        $client = new XmlApiClient(
            $transport->client(),
            $transport->requestFactory(),
            $transport->streamFactory(),
            $tokenProvider,
            $clock,
            $configuration,
            $logger,
        );

        return new UrssafHttpGateway($credentials, $client, $configuration, new AnswerInterpreter($clock));
    }
}
