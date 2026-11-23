<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Fictitious listed returns, shared by the application tests.
 */
final class SampleReturns
{
    /**
     * A listed return with a document (a legacy `id`), published at `$publishedAt` (null: an unparsable publication).
     */
    public static function listed(string $flowId, int $nature, string $status, ?string $publishedAt, ?string $ops): ReturnReference
    {
        return ReturnReference::fromListing(
            Samples::flowId($flowId),
            $nature,
            $status,
            self::rawDate($publishedAt),
            '20220201000000',
            self::instant($publishedAt),
            self::instant($publishedAt),
            '7',
            $ops,
            null,
            null,
        );
    }

    /**
     * A listed return without any document: nothing to download yet.
     */
    public static function listedWithoutDocument(string $flowId, int $nature, string $status, ?string $publishedAt, ?string $ops): ReturnReference
    {
        return ReturnReference::fromListing(
            Samples::flowId($flowId),
            $nature,
            $status,
            self::rawDate($publishedAt),
            '20220201000000',
            self::instant($publishedAt),
            self::instant($publishedAt),
            null,
            $ops,
            null,
            null,
        );
    }

    private static function instant(?string $iso8601): ?Instant
    {
        return $iso8601 === null ? null : Samples::at($iso8601);
    }

    private static function rawDate(?string $iso8601): string
    {
        return $iso8601 === null ? 'unparsable' : str_replace(['-', ':', 'T', 'Z'], '', $iso8601);
    }
}
