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

/**
 * Ids of the private services of the bundle that have no interface to be identified by (ADR 0009: `urssaf_dpae.*`).
 */
final class ServiceIds
{
    public const SYMFONY_HTTP_CLIENT = 'urssaf_dpae.http_client';
    public const PSR18_CLIENT = 'urssaf_dpae.psr18_client';
    public const HTTP_TRANSPORT = 'urssaf_dpae.http_transport';
    public const PSR_CLOCK = 'urssaf_dpae.psr_clock';
    public const API_CONFIGURATION = 'urssaf_dpae.api_configuration';
    public const GATEWAY_OPTIONS = 'urssaf_dpae.gateway_options';
    public const INSTALLATION_MODE = 'urssaf_dpae.installation_mode';

    /** Token provider of the "urssaf:dpae:check" command: its own in-memory store, so that every check asks URSSAF. */
    public const PROBE_TOKEN_PROVIDER = 'urssaf_dpae.probe_token_provider';

    /** Name reserved for a dedicated PSR-6 pool of a host-provided token store (ADR 0009: the package caches nothing). */
    public const TOKEN_CACHE_POOL = 'urssaf_dpae.token_cache';
}
