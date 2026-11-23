<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http;

use TheBeardedBear\UrssafDpae\Application\Port\Operation;

/**
 * Description of one authenticated API call, independent from the HTTP library: an absolute URI and, for the deposit,
 * the flow to compress and send.
 *
 * @internal
 */
final readonly class ApiRequest
{
    private function __construct(
        private Operation $operation,
        private string $method,
        private string $uri,
        private ?string $body,
        private string $contentType,
    ) {}

    public static function get(Operation $operation, string $uri): self
    {
        return new self($operation, 'GET', $uri, null, '');
    }

    /**
     * @param string $body uncompressed bytes: the client gzips them once and sets `Content-Encoding: gzip`
     */
    public static function postGzip(Operation $operation, string $uri, string $body, string $contentType): self
    {
        return new self($operation, 'POST', $uri, $body, $contentType);
    }

    public function operation(): Operation
    {
        return $this->operation;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * Empty without a body.
     */
    public function contentType(): string
    {
        return $this->contentType;
    }
}
