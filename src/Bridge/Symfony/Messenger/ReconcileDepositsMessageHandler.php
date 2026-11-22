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

use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\RetryDelays;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Reconciles the deposits of unknown outcome of the tenant. A refusal of the polling constraints is retried after the
 * advised delay.
 */
final readonly class ReconcileDepositsMessageHandler
{
    public function __construct(
        private ReconcileUnknownDepositsHandler $reconcile,
        private OperationLocks $locks,
    ) {}

    public function __invoke(ReconcileDepositsMessage $message): void
    {
        // Validated before the lock: its name is built from the validated tenant only.
        $tenantId = MessageFailures::guard(static fn(): TenantId => TenantId::fromString($message->tenantId));
        $this->locks->exclusively(
            'reconcile',
            [$tenantId->value()],
            function () use ($tenantId): void {
                $report = MessageFailures::guard(fn(): mixed => ($this->reconcile)(new ReconcileUnknownDeposits($tenantId)));
                if ($report->refused) {
                    throw MessageFailures::refused('reconcile', RetryDelays::seconds($report->retryAfterSeconds));
                }
            },
            static fn(string $resource): never => throw MessageFailures::busy($resource),
        );
    }
}
