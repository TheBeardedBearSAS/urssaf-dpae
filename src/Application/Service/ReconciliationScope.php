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

use DateInterval;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * What one reconciliation run looks at (ADR 0005): the windows of the deposits read (oldest claims first), the ones that can be
 * reconciled (`live`: sent and answered or unknown, window still inside the 13 months URSSAF keeps), the ones that cannot
 * (`expired`), and the `horizon` of a full batch.
 */
final readonly class ReconciliationScope
{
    /**
     * @param list<ClaimWindow> $windows every deposit read
     * @param list<ClaimWindow> $live    the deposits to reconcile
     * @param list<ClaimWindow> $expired the deposits whose window ended before `$floor`
     */
    private function __construct(
        public array $windows,
        public array $live,
        public array $expired,
        public ?Instant $horizon,
        public Instant $floor,
    ) {}

    /**
     * @param list<Deposit> $deposits the deposits without a flow id, the oldest claims first, at most one batch
     */
    public static function of(array $deposits, Instant $now, WorkflowPolicy $policy): self
    {
        $windows = array_map(static fn(Deposit $deposit): ClaimWindow => ClaimWindow::of($deposit, $policy), $deposits);
        $floor = Instants::minus($now, new DateInterval(WorkflowPolicy::MAX_LOOKBACK));
        $live = [];
        $expired = [];
        foreach ($windows as $window) {
            if ($window->deposit->state() === DepositState::Depositing) {
                continue;
            }
            if ($window->end->isBefore($floor)) {
                $expired[] = $window;

                continue;
            }
            $live[] = $window;
        }
        // A full batch may have left deposits unread: the last window read bounds what is known.
        $horizon = \count($windows) >= WorkflowPolicy::RECONCILIATION_BATCH ? $windows[\count($windows) - 1]->start : null;

        return new self($windows, $live, $expired, $horizon, $floor);
    }

    /**
     * Whether every deposit that could compete for the flux of the target has been read.
     */
    public function readsEveryCompetitorOf(ClaimWindow $target): bool
    {
        return !$this->horizon instanceof Instant || $this->horizon->isAfter($target->end);
    }

    /**
     * The range to list (there must be a live window): the widest live window, from no earlier than 13 months ago to no later than `$now`.
     *
     * @return array{Instant, Instant}
     */
    public function range(Instant $now): array
    {
        /** @var non-empty-list<ClaimWindow> $live */
        $live = $this->live;
        [$start, $end] = ClaimWindow::span($live);

        return [$start->isBefore($this->floor) ? $this->floor : $start, $end->isAfter($now) ? $now : $end];
    }
}
