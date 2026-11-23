<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger;

use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\RetryDelays;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Synchronises the returns of the tenant. A refusal of the polling constraints is retried after the advised delay.
 */
final readonly class SynchronizeReturnsMessageHandler
{
    public function __construct(
        private SynchronizeReturnsHandler $synchronize,
        private OperationLocks $locks,
    ) {}

    public function __invoke(SynchronizeReturnsMessage $message): void
    {
        // Validated before the lock: its name is built from the validated tenant only.
        $tenantId = MessageFailures::guard(static fn(): TenantId => TenantId::fromString($message->tenantId));
        $this->locks->exclusively(
            'sync-returns',
            [$tenantId->value()],
            function () use ($tenantId): void {
                $report = MessageFailures::guard(fn(): mixed => ($this->synchronize)(new SynchronizeReturns($tenantId)));
                if ($report->refused) {
                    throw MessageFailures::refused('returns', RetryDelays::seconds($report->retryAfterSeconds));
                }
            },
            static fn(string $resource): never => throw MessageFailures::busy($resource),
        );
    }
}
