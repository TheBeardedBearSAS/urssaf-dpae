<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit;

use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Shared\ForbidsSerialization;

/**
 * An answer document kept verbatim: the bytes as received and the raw `Content-Type` header value (possibly empty).
 * Nothing is decoded or interpreted. The bytes may contain personal data: `__debugInfo()` shows only their size and
 * SHA-256, and the object cannot be serialized.
 */
final readonly class RawDocument
{
    use ForbidsSerialization;

    private function __construct(
        private string $bytes,
        private string $contentType,
    ) {}

    /**
     * @throws InvalidValue `raw_document.empty` when the bytes are blank
     */
    public static function of(string $bytes, string $contentType): self
    {
        if (trim($bytes) === '') {
            throw InvalidValue::empty('raw_document');
        }

        return new self($bytes, $contentType);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function contentType(): string
    {
        return $this->contentType;
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
