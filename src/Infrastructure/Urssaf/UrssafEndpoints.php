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
 * Paths of the URSSAF DPAE API as published in the Swagger v1.0.0, appended to the base URI of the matching
 * {@see DpaeService} ({@see ServiceEndpoints}). Only the authentication path carries `/authentifier_dpae`; the deposit
 * path has no trailing slash (the PDF writes one, an open point settled by the contract tests, ADR 0002).
 */
final class UrssafEndpoints
{
    public const AUTHENTICATION_PATH = '/authentifier_dpae';
    public const DEPOSIT_PATH = '/deposer-dsn/1.0';
    public const RETURNS_LIST_PATH = '/lister-retours-declarant/1.0';
    public const RETURN_DOWNLOAD_PATH = '/telecharger-retour/1.0';
}
