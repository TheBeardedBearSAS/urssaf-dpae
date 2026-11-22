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

use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\DpaeTokenProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\XmlApiClient;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;

/**
 * The internal collaborators of the gateway, wired on the doubles of an {@see HttpStack}.
 */
final class Wiring
{
    public static function provider(HttpStack $stack): DpaeTokenProvider
    {
        return new DpaeTokenProvider(
            $stack->client,
            $stack->factory,
            $stack->factory,
            $stack->tokens,
            $stack->clock,
            $stack->configuration,
            IdentifiantsXmlWriter::utf8(),
            $stack->logger,
        );
    }

    public static function client(HttpStack $stack): XmlApiClient
    {
        return new XmlApiClient(
            $stack->client,
            $stack->factory,
            $stack->factory,
            self::provider($stack),
            $stack->clock,
            $stack->configuration,
            $stack->logger,
        );
    }
}
