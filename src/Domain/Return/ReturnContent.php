<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;

/**
 * Content of a downloaded return "dans son format d'origine": the raw bytes and the raw `Content-Type` header value.
 * The header is kept as received; {@see self::type()} interprets it and is null for an unknown format.
 *
 * The bytes may contain personal data: `__debugInfo()` shows only their size and SHA-256, and the object cannot be
 * serialized.
 */
final readonly class ReturnContent
{
    use ForbidsSerialization;

    private function __construct(
        private string $bytes,
        private string $contentType,
    ) {}

    /**
     * @throws InvalidValue `return_content.empty`
     */
    public static function of(string $bytes, string $contentType): self
    {
        if ($bytes === '') {
            throw InvalidValue::empty('return_content');
        }

        return new self($bytes, $contentType);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    /**
     * The `Content-Type` header value, as received.
     */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * Null when the content type is not one of the formats known to this version of the package.
     */
    public function type(): ?ReturnContentType
    {
        return ReturnContentType::fromHeader($this->contentType);
    }

    public function size(): int
    {
        return \strlen($this->bytes);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['size' => $this->size(), 'sha256' => hash('sha256', $this->bytes), 'contentType' => $this->contentType];
    }
}
