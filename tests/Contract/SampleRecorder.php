<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Contract;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 decorator that keeps the answers of the live hosts as local samples (api-reference.md section 5.9: no official
 * example of the returns is published), in `var/contract-samples` (git-ignored, files `0600`).
 *
 * The authentication call is never recorded (its answer is the token, and its request carries the password), and neither
 * is any request. A sample may hold personal data of a real declarant: it is for reading, never committed; anonymise it
 * by hand before turning it into a fixture of `tests/Fixtures/wire`.
 */
final class SampleRecorder implements ClientInterface
{
    public const DIRECTORY = __DIR__ . '/../../var/contract-samples';

    private int $count = 0;

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly StreamFactoryInterface $streams,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->inner->sendRequest($request);
        if (str_ends_with(rtrim($request->getUri()->getPath(), '/'), '/authentifier_dpae')) {
            return $response;
        }

        $body = (string) $response->getBody();
        $this->record($request, $response, $body);

        return $response->withBody($this->streams->createStream($body));
    }

    private function record(RequestInterface $request, ResponseInterface $response, string $body): void
    {
        // Personal data: nothing readable by others, whatever the umask of the host, even for the instant before chmod.
        $previousUmask = umask(0o077);

        try {
            $this->write($request, $response, $body);
        } finally {
            umask($previousUmask);
        }
    }

    private function write(RequestInterface $request, ResponseInterface $response, string $body): void
    {
        if (!is_dir(self::DIRECTORY) && !mkdir(self::DIRECTORY, 0o700, true) && !is_dir(self::DIRECTORY)) {
            return;
        }

        $segments = explode('/', trim($request->getUri()->getPath(), '/'));
        $operation = preg_replace('/[^a-z0-9-]+/', '-', strtolower($segments[0]));
        $path = \sprintf(
            '%s/%s-%02d-%s-%s-%d',
            self::DIRECTORY,
            gmdate('Ymd\THis\Z'),
            ++$this->count,
            $request->getMethod(),
            $operation,
            $response->getStatusCode(),
        );
        $contentType = $response->getHeaderLine('Content-Type');
        file_put_contents($path . '.body', $body);
        file_put_contents($path . '.meta', \sprintf("status: %d\ncontent-type: %s\nbytes: %d\n", $response->getStatusCode(), $contentType, \strlen($body)));
        chmod($path . '.body', 0o600);
        chmod($path . '.meta', 0o600);
    }
}
