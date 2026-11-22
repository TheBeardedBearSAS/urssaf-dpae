<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\DpaeTokenProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

/**
 * Container factories of the HTTP gateway collaborators that depend on optional application services.
 */
final class GatewayFactory
{
    public static function options(ClockInterface $clock, ?LoggerInterface $logger): GatewayOptions
    {
        $options = GatewayOptions::defaults()->withClock($clock);

        return $logger instanceof LoggerInterface ? $options->withLogger($logger) : $options;
    }

    /**
     * The token provider of the installation check: a store of its own, so that every check asks URSSAF and the tokens of
     * the application are left untouched.
     */
    public static function probeTokenProvider(HttpTransport $transport, ClockInterface $clock, ApiConfiguration $configuration, ?LoggerInterface $logger): DpaeTokenProvider
    {
        return new DpaeTokenProvider(
            $transport->client(),
            $transport->requestFactory(),
            $transport->streamFactory(),
            new InMemoryTokenStore(),
            $clock,
            $configuration,
            IdentifiantsXmlWriter::withEncoding($configuration->authenticationEncoding()),
            $logger ?? new NullLogger(),
        );
    }
}
