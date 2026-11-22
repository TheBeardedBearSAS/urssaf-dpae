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

/**
 * Paths of the URSSAF DPAE API as published in the Swagger v1.0.0 (host api.urssaf.fr, basePath /authentifier_dpae).
 * The DPAE API does not use OAuth2: a token is obtained from AUTHENTICATION_PATH with an XML "identifiants" body.
 * The PDF guides publish dedicated hosts instead; see docs/api-reference.md (open points).
 */
final class UrssafEndpoints
{
    public const API_BASE_PATH = '/authentifier_dpae';
    public const AUTHENTICATION_PATH = self::API_BASE_PATH . '/authentifier_dpae';
    public const DEPOSIT_PATH = self::API_BASE_PATH . '/deposer-dsn/1.0';
    public const RETURNS_LIST_PATH = self::API_BASE_PATH . '/lister-retours-declarant/1.0';
    public const RETURN_DOWNLOAD_PATH = self::API_BASE_PATH . '/telecharger-retour/1.0';
}
