<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Id;

use Random\RandomException;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;

/**
 * Random version 4 UUIDs (RFC 9562, lower case), from 16 random bytes.
 */
final readonly class RandomIdGenerator implements IdGenerator
{
    private const BYTES = 16;

    public function __construct(private RandomBytesSource $randomBytes) {}

    /**
     * @return non-empty-string
     *
     * @throws RandomException when no secure source of randomness is available
     */
    public function generate(): string
    {
        $bytes = $this->randomBytes->bytes(self::BYTES);
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
