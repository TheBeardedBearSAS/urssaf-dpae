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
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Logs a call once its request was sent. A failing logger or clock must change nothing: the answer (or the transport
 * failure) is what the caller needs, and a deposit that was sent has an unknown outcome whatever the logs do.
 *
 * @internal
 */
final readonly class CallLog
{
    public function __construct(private LoggerInterface $logger, private ClockInterface $clock) {}

    /**
     * @mago-expect lint:no-empty-catch-clause A logger or clock failure after the send must change nothing
     *
     * @param array<string, int|string> $context logged with the `duration_ms` since `$startedAt`
     */
    public function write(string $level, string $message, DateTimeImmutable $startedAt, array $context): void
    {
        try {
            $context['duration_ms'] = Duration::millisecondsSince($startedAt, $this->clock->now());
            $this->logger->log($level, $message, $context);
        } catch (Throwable) {
            // Intentionally ignored: see the class comment.
        }
    }
}
