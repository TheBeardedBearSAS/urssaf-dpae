<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Dates on the wire (ADR 0006, api-reference section 5.8): `yyyyMMddHHmmss` on a 24-hour clock in a configured time
 * zone (`Europe/Paris` by default, the zone is not published). The lower-case `hh` of the Swagger is read as a typo.
 */
final readonly class WireDate
{
    public const FORMAT = 'YmdHis';
    public const DEFAULT_TIME_ZONE = 'Europe/Paris';

    /**
     * Digits with optional separators (`-`, `T` or space, `:`), optional fraction, optional explicit offset.
     */
    private const PATTERN = '/^(\d{4})-?(\d{2})-?(\d{2})[T ]?(\d{2}):?(\d{2}):?(\d{2})(?:[.,]\d+)?(Z|[+-]\d{2}(?::?\d{2})?)?$/D';

    private function __construct(
        private DateTimeZone $zone,
    ) {}

    public static function inZone(DateTimeZone $zone): self
    {
        return new self($zone);
    }

    public static function defaultZone(): self
    {
        return new self(new DateTimeZone(self::DEFAULT_TIME_ZONE));
    }

    /**
     * `20220130083000`, the clock of the configured zone.
     */
    public function format(Instant $instant): string
    {
        return $instant->inTimeZone($this->zone)->format(self::FORMAT);
    }

    /**
     * Tolerant reading of `publication` / `production`: null (never an exception) when the value is not a date that
     * exists on the clock of the zone (an impossible date or a clock hour skipped by a time change); the caller keeps
     * the raw string. An explicit offset in the value wins over the configured zone.
     */
    public function parse(string $value): ?Instant
    {
        $parts = [];
        if (preg_match(self::PATTERN, trim($value), $parts) !== 1) {
            return null;
        }

        $digits = $parts[1] . $parts[2] . $parts[3] . $parts[4] . $parts[5] . $parts[6];
        $offset = $parts[7] ?? '';
        try {
            $date = new DateTimeImmutable(
                \sprintf('%s-%s-%s %s:%s:%s', $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[6]),
                $offset === '' ? $this->zone : new DateTimeZone($offset),
            );
        } catch (Exception) {
            return null;
        }

        return $date->format(self::FORMAT) === $digits ? Instant::fromDateTime($date) : null;
    }
}
