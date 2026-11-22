<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Dto;

use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;

/**
 * Outcome of one `ReconcileUnknownDeposits` run.
 */
final readonly class ReconciliationReport
{
    /**
     * @param list<DepositId>        $markedUnknown     deposits left `depositing` too long, now `outcome_unknown`
     * @param list<DepositId>        $adopted           deposits that took a flow id by reconciliation (`Deposit::adoptedByReconciliation()`),
     *                                                  and, if unknown, a final state
     * @param list<ManualReviewItem> $needingReview     deposits the package cannot settle alone, with the reason
     * @param list<FlowId>           $unattachedFlux    nature `10` flux of the tenant that no local deposit holds and whose publication is in
     *                                                  no deposit's window (another channel, or a deposit this package did not record):
     *                                                  for a human to look at, never adopted
     * @param bool                   $refused           URSSAF refused the listing (HTTP 422 / 429): nothing was adopted, retry later
     * @param ?int                   $retryAfterSeconds delay advised when refused
     */
    public function __construct(
        public array $markedUnknown,
        public array $adopted,
        public array $needingReview,
        public array $unattachedFlux,
        public bool $refused,
        public ?int $retryAfterSeconds,
    ) {}

    /**
     * @param list<DepositId>        $markedUnknown
     * @param list<DepositId>        $adopted
     * @param list<ManualReviewItem> $needingReview
     * @param list<FlowId>           $unattachedFlux
     */
    public static function settled(array $markedUnknown, array $adopted, array $needingReview, array $unattachedFlux): self
    {
        return new self($markedUnknown, $adopted, $needingReview, $unattachedFlux, false, null);
    }

    /**
     * URSSAF refused the listing (HTTP 422 / 429): nothing was adopted, retry after `$retryAfterSeconds`.
     *
     * @param list<DepositId>        $markedUnknown
     * @param list<ManualReviewItem> $needingReview
     */
    public static function refused(array $markedUnknown, array $needingReview, int $retryAfterSeconds): self
    {
        return new self($markedUnknown, [], $needingReview, [], true, $retryAfterSeconds);
    }
}
