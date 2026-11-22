<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use Throwable;

/**
 * Logs a failed event publication at `error` level: the exception class and the number of events, never a message
 * (it may embed personal data).
 */
final readonly class PsrLoggerFailureReporter implements FailureReporter
{
    public function __construct(private LoggerInterface $logger) {}

    public function eventPublicationFailed(Throwable $failure, array $events): void
    {
        $this->logger->error('A domain event listener failed after the commit.', [
            'exception' => $failure::class,
            'events' => \count($events),
        ]);
    }
}
