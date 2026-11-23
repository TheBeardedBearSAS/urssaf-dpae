<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Moves the `depositing` deposits left behind by a crash between the claim and the answer to `outcome_unknown`
 * (ADR 0005): the flow may have been received, so it is reconciled, never resent. A deposit whose answer arrived meanwhile
 * (another worker saved it first) is left as that worker made it: the sweep reloads it, and only moves it if it is still
 * a stale `depositing` one.
 */
final readonly class StaleDepositSweeper
{
    public const STALE = 'deposit.stale';

    public function __construct(
        private DepositRepository $deposits,
        private DepositUpdater $updater,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @return list<DepositId> the deposits moved to `outcome_unknown` (at most {@see WorkflowPolicy::RECONCILIATION_BATCH})
     */
    public function sweep(TenantId $tenantId, Instant $now): array
    {
        $claimedBefore = Instants::minus($now, $this->policy->staleDepositAfter());
        $swept = [];
        foreach ($this->deposits->staleDepositing($tenantId, $claimedBefore, WorkflowPolicy::RECONCILIATION_BATCH) as $deposit) {
            $moved = $this->updater->apply($deposit, $now, static function (Deposit $deposit, Instant $now) use ($claimedBefore): bool {
                if ($deposit->state() !== DepositState::Depositing || !$deposit->claimedAt()->isBefore($claimedBefore)) {
                    return false;
                }
                $deposit->markOutcomeUnknown(self::STALE, $now);

                return true;
            });
            if ($moved) {
                $swept[] = $deposit->id();
            }
        }

        return $swept;
    }
}
