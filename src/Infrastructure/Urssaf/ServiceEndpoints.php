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

use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceKind;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * The base URI of each {@see DpaeService} (ADR 0002). Every one is overridable, the authentication one included, so a
 * mock server can stand for all hosts. A base URI is `scheme://host[:port][/path]` with no credentials, query nor
 * fragment; a path prefix is kept and the service path appended to it.
 *
 * The concentrator port is added to the **default** deposit, consultation and download URIs of a concentrator identity
 * (service code `98`), never to the authentication URI, to an overridden URI (used verbatim for every tenant) nor to
 * another service code.
 */
final readonly class ServiceEndpoints
{
    public const DEFAULT_CONCENTRATOR_PORT = 8443;

    /**
     * @param array<string, string> $overrides base URIs without trailing slash, keyed by {@see DpaeService::$value}
     */
    private function __construct(private array $overrides, private int $concentratorPort) {}

    public static function defaults(): self
    {
        return new self([], self::DEFAULT_CONCENTRATOR_PORT);
    }

    /**
     * `http` is accepted only for a mock server: never for one of the four published URSSAF hosts, which would send the
     * token and the password in clear.
     *
     * @throws InvalidValue `base_uri.invalid_format`, `base_uri.insecure_scheme`
     */
    public function withBaseUri(DpaeService $service, string $baseUri): self
    {
        $parts = WebUri::parse($baseUri);
        if ($parts === null || str_contains($baseUri, '?') || str_contains($baseUri, '#')) {
            throw InvalidValue::invalidFormat('base_uri', 'scheme://host[:port][/path] without credentials, query or fragment');
        }
        if ($parts['scheme'] === 'http' && self::isPublishedHost($parts['host'])) {
            throw InvalidValue::because('base_uri.insecure_scheme', 'Invalid base_uri: `http` is refused for a published URSSAF host, use `https`.');
        }

        return new self([$service->value => rtrim($baseUri, '/')] + $this->overrides, $this->concentratorPort);
    }

    private static function isPublishedHost(string $host): bool
    {
        foreach (DpaeService::cases() as $service) {
            if (strtolower(rtrim($host, '.')) === parse_url($service->defaultBaseUri(), PHP_URL_HOST)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidValue `concentrator_port.out_of_range` outside 1..65535
     */
    public function withConcentratorPort(int $port): self
    {
        if ($port < 1 || $port > 65_535) {
            throw InvalidValue::because('concentrator_port.out_of_range', 'Invalid concentrator_port: the value must be between 1 and 65535.', ['min' => 1, 'max' => 65_535]);
        }

        return new self($this->overrides, $port);
    }

    public function concentratorPort(): int
    {
        return $this->concentratorPort;
    }

    /**
     * Base URI of the service for the identity: the override when there is one, else the published host, with the
     * concentrator port for a concentrator (never for the authentication service).
     */
    public function baseUri(DpaeService $service, ServiceCode $serviceCode): string
    {
        $override = $this->overrides[$service->value] ?? null;
        if ($override !== null) {
            return $override;
        }

        if ($service !== DpaeService::Authentication && $serviceCode->kind() === ServiceKind::Concentrator) {
            return $service->defaultBaseUri() . ':' . $this->concentratorPort;
        }

        return $service->defaultBaseUri();
    }

    /**
     * @param string $path one of the {@see UrssafEndpoints} paths, optionally followed by encoded segments
     */
    public function uri(DpaeService $service, ServiceCode $serviceCode, string $path): string
    {
        return $this->baseUri($service, $serviceCode) . $path;
    }

    /**
     * True when the origin (scheme, host, effective port) of `$url` equals that of the download base URI of the
     * identity. Host names are compared case-insensitively, `443` is the effective port of an `https` URI without a
     * port, and a URL carrying credentials is never on the origin (the download sends the token, ADR 0002).
     */
    public function isOnDownloadOrigin(string $url, ServiceCode $serviceCode): bool
    {
        return WebUri::originOf($url) === WebUri::originOf($this->baseUri(DpaeService::Download, $serviceCode));
    }
}
