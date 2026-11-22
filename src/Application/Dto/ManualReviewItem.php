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
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * A deposit the reconciliation could not settle and a human must look at (ADR 0005): the evidence is the external
 * reference, the fingerprint, the claim instant and the candidate flux ids; the reason says why.
 */
final readonly class ManualReviewItem
{
    /**
     * Still unsettled after `manualReviewAfter`.
     */
    public const UNKNOWN_OUTCOME = 'unknown_outcome';

    /**
     * A sole candidate was found but the policy does not adopt alone (`WorkflowPolicy::autoAdopt()`).
     */
    public const ADOPTION_NOT_AUTOMATIC = 'adoption_not_automatic';

    /**
     * The window of the claim is older than what URSSAF keeps (13 months): the listing cannot find the flux any more.
     */
    public const OUT_OF_RETENTION = 'out_of_retention';

    /**
     * @param list<FlowId> $candidates the flux published in the deposit's window and still unclaimed by another deposit
     */
    public function __construct(
        public DepositId $depositId,
        public ExternalReference $externalReference,
        public FlowFingerprint $fingerprint,
        public Instant $claimedAt,
        public array $candidates,
        public string $reason,
    ) {}
}
