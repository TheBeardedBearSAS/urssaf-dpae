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
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnStatus;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Lets a deposit adopt the flux published for it (ADR 0005), only when
 *
 * - the deposit's window is closed (`now` is after its end), so no later flux can still compete;
 * - the flux is the sole candidate in the window and no other deposit without a flow id (`depositing` ones included)
 *   has a window containing its publication;
 * - the policy allows it ({@see WorkflowPolicy::autoAdopt()}), else a human decides.
 *
 * An `outcome_unknown` deposit also settles: `OK` is a receipt, `KO` a rejection notice (inferred, api-reference.md
 * section 5.9); any other status leaves it unknown. The change is made under optimistic locking: if the deposit moved
 * meanwhile it is reloaded, and adopted only if it still is an unknown outcome or an answer without a flow id.
 */
final readonly class DepositAdopter
{
    public function __construct(
        private DepositUpdater $updater,
        private WorkflowPolicy $policy,
    ) {}

    /**
     * @param list<ReturnReference> $matching the candidates published in the target's window
     * @param list<ClaimWindow>     $windows  the windows of every claimed deposit of the tenant without a flow id
     */
    public function adopt(ClaimWindow $target, array $matching, array $windows, Instant $now): Adoption
    {
        $candidate = CandidateSelection::sole($target, $matching, $windows);
        if (!$candidate instanceof ReturnReference || !$now->isAfter($target->end) || !self::settles($target->deposit, $candidate->status())) {
            return Adoption::Undecided;
        }
        if (!$this->policy->autoAdopt($target->deposit)) {
            return Adoption::NeedsReview;
        }

        $adopted = $this->updater->apply(
            $target->deposit,
            $now,
            static fn(Deposit $deposit, Instant $now): bool => self::apply($deposit, $candidate, $now),
        );

        return $adopted ? Adoption::Adopted : Adoption::Undecided;
    }

    /**
     * Whether a flux of that status gives the deposit, as it now is, something to settle.
     */
    private static function settles(Deposit $deposit, ?ReturnStatus $status): bool
    {
        if ($deposit->state() === DepositState::OutcomeUnknown) {
            return $status === ReturnStatus::Ok || $status === ReturnStatus::Failed;
        }

        return ($deposit->state() === DepositState::Received || $deposit->state() === DepositState::Rejected) && !$deposit->flowId() instanceof FlowId;
    }

    private static function apply(Deposit $deposit, ReturnReference $candidate, Instant $now): bool
    {
        $status = $candidate->status();
        if (!self::settles($deposit, $status)) {
            return false;
        }
        if ($deposit->state() !== DepositState::OutcomeUnknown) {
            $deposit->gainFlowId($candidate->flowId(), $now);

            return true;
        }
        if ($status === ReturnStatus::Ok) {
            $deposit->adoptAsReceived($candidate->flowId(), $now);

            return true;
        }
        $deposit->adoptAsRejected($candidate->flowId(), $now);

        return true;
    }
}
