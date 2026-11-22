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

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * A PSR-18 client that throws what it is given, even an Error (which the mock client cannot queue).
 */
final class ThrowingClient implements ClientInterface
{
    private int $calls = 0;

    public function __construct(private readonly Throwable $failure) {}

    public function calls(): int
    {
        return $this->calls;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        ++$this->calls;

        throw $this->failure;
    }
}
