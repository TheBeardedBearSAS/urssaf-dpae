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

use DateTimeZone;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\DpaeService;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ServiceEndpoints;

/**
 * Container factory of {@see ApiConfiguration}: built when the service is first used, so that the declaration mode and the
 * four base URIs can be `%env()%` placeholders (an unset or empty override keeps the default of NAT/5492).
 *
 * @mago-expect lint:excessive-parameter-list One argument per configuration key, as the container passes them
 */
final class ApiConfigurationFactory
{
    /**
     * @param array<string, ?string> $hosts base URI override by {@see DpaeService} value, null or empty to keep the default
     *
     * @throws InvalidValue when an option is not valid
     */
    public static function create(
        DeclarationMode $declarationMode,
        array $hosts,
        int $concentratorPort,
        string $authenticationEncoding,
        string $depositContentType,
        int $maxFlowBytes,
        int $maxResponseBytes,
        string $returnsTimeZone,
    ): ApiConfiguration {
        return ApiConfiguration::defaults()
            ->withEndpoints(self::endpoints($hosts, $concentratorPort))
            ->withAuthenticationEncoding($authenticationEncoding)
            ->withDepositContentType($depositContentType)
            ->withMaxFlowBytes($maxFlowBytes)
            ->withMaxResponseBytes($maxResponseBytes)
            ->withReturnsTimeZone(new DateTimeZone($returnsTimeZone))
            ->withDeclarationMode($declarationMode);
    }

    /**
     * @param array<string, ?string> $hosts
     */
    private static function endpoints(array $hosts, int $concentratorPort): ServiceEndpoints
    {
        $endpoints = ServiceEndpoints::defaults()->withConcentratorPort($concentratorPort);
        foreach (DpaeService::cases() as $service) {
            $uri = $hosts[$service->value] ?? null;
            if ($uri === null || $uri === '') {
                continue;
            }
            $endpoints = $endpoints->withBaseUri($service, $uri);
        }

        return $endpoints;
    }
}
