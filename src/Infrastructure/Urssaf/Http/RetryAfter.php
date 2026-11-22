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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads a `Retry-After` header: a delay in seconds, or an HTTP date (`Sun, 06 Nov 1994 08:49:37 GMT`) counted from
 * `$now` (ADR 0006). No `Retry-After` is published for the DPAE API, so any other value is ignored.
 *
 * @internal
 */
final class RetryAfter
{
    private const HTTP_DATE = 'D, d M Y H:i:s \G\M\T';

    /**
     * @return int|null seconds to wait, never negative; null when the value is empty or not understood
     */
    public static function seconds(string $value, DateTimeImmutable $now): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return (int) $value;
        }

        $date = DateTimeImmutable::createFromFormat(self::HTTP_DATE, $value, new DateTimeZone('UTC'));
        if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now->getTimestamp());
    }
}
