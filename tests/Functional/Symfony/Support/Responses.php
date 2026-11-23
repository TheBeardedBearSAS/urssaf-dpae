<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ResponseInterface;

/**
 * Canned HTTP answers of URSSAF.
 */
final class Responses
{
    // @mago-expect lint:no-literal-password A fixture token, never a real one.
    public const TOKEN = 'FICTITIOUS-TOKEN-FOR-TESTS';

    private function __construct() {}

    public static function token(): ResponseInterface
    {
        return self::response(200, self::TOKEN, 'application/octet-stream');
    }

    public static function status(int $status): ResponseInterface
    {
        return self::response($status, '', 'text/html');
    }

    public static function response(int $status, string $body, string $contentType): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse($status)->withHeader('Content-Type', $contentType)->withBody($factory->createStream($body));
    }
}
