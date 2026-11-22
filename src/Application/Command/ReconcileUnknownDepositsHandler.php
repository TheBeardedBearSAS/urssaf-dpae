<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Command;

use TheBeardedBear\UrssafDpae\Application\Dto\ManualReviewItem;
use TheBeardedBear\UrssafDpae\Application\Dto\ReconciliationReport;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Service\Adoption;
use TheBeardedBear\UrssafDpae\Application\Service\CandidateSelection;
use TheBeardedBear\UrssafDpae\Application\Service\ClaimWindow;
use TheBeardedBear\UrssafDpae\Application\Service\DepositAdopter;
use TheBeardedBear\UrssafDpae\Application\Service\FluxCandidates;
use TheBeardedBear\UrssafDpae\Application\Service\Instants;
use TheBeardedBear\UrssafDpae\Application\Service\ListingWindows;
use TheBeardedBear\UrssafDpae\Application\Service\ReconciliationScope;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\StaleDepositSweeper;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Settles the deposits of a tenant that have no known flow id (ADR 0005). It never resends a flow.
 *
 * 1. a `depositing` deposit older than `staleDepositAfter` (crash between claim and answer) becomes `outcome_unknown`;
 * 2. a deposit whose window ended more than 13 months ago is out of what URSSAF keeps: it goes to manual review;
 * 3. the listing covers the widest remaining claim window `[claimedAt - skew, claimedAt + window]`, capped to
 *    `[now - 13 months, now]` and cut into ranges of at most `pollingMaxRange`, like the synchronisation;
 * 4. a candidate is a `flux.id` with a nature `10` return published in a deposit's window and not linked to a local
 *    deposit of the tenant;
 * 5. a deposit adopts a candidate ({@see DepositAdopter}) only once its window is closed, if it is the only one in its
 *    window **and** no other deposit without a flow id (`depositing` ones included) has a window containing the
 *    candidate's publication, **and** the policy allows it (`WorkflowPolicy::autoAdopt()`, else manual review). An
 *    `outcome_unknown` deposit then becomes `received` (status `OK`) or `rejected` (status `KO`, inferred,
 *    api-reference.md section 5.9); any other status leaves it unknown for the next run. A `received` / `rejected` one
 *    only gains the flow id. The adoption is marked (`Deposit::adoptedByReconciliation()`, `DepositAdopted`);
 * 6. what stays unknown past `manualReviewAfter` is reported for a human, and so are the nature `10` flux that no deposit
 *    holds and no window contains (another channel, or a deposit this package did not record).
 *
 * At most {@see WorkflowPolicy::RECONCILIATION_BATCH} deposits are read per run (oldest claims first): a deposit is
 * only adopted once every deposit that could compete for its flux has been read. A polling refusal (HTTP 422 / 429) is
 * reported, not thrown: nothing is adopted and the next run retries.
 *
 * Deviation, ADR 0005 amendment: the nature `10` document is not downloaded, so the declarant SIRET is not compared
 * (the AEE schema is not published and the Application layer has no envoi reader); the sole-candidate and
 * no-competing-window rules above are the only filters.
 */
final readonly class ReconcileUnknownDepositsHandler
{
    public function __construct(
        private DepositRepository $deposits,
        private DpaeGateway $gateway,
        private StaleDepositSweeper $sweeper,
        private FluxCandidates $candidates,
        private DepositAdopter $adopter,
        private ReturnIngestion $ingestion,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    public function __invoke(ReconcileUnknownDeposits $command): ReconciliationReport
    {
        $tenantId = $command->tenantId;
        $now = Instant::fromDateTime($this->clock->now());
        $markedUnknown = $this->sweeper->sweep($tenantId, $now);
        $scope = ReconciliationScope::of($this->deposits->withoutFlowId($tenantId, WorkflowPolicy::RECONCILIATION_BATCH), $now, $this->policy);
        $needingReview = array_map(static fn(ClaimWindow $window): ManualReviewItem => self::reviewItem($window, [], ManualReviewItem::OUT_OF_RETENTION), $scope->expired);
        if ($scope->live === []) {
            return ReconciliationReport::settled($markedUnknown, [], $needingReview, []);
        }

        try {
            $references = $this->list($tenantId, ...$scope->range($now));
        } catch (PollingConstraintViolated $refusal) {
            return ReconciliationReport::refused($markedUnknown, $needingReview, $refusal->retryAfterSeconds() ?? $this->policy->pollingRetryDelaySeconds());
        }
        $candidates = $this->candidates->among($tenantId, $references);

        $adopted = [];
        foreach ($scope->live as $target) {
            $matching = array_values(array_filter($candidates, $target->containsPublicationOf(...)));
            $adoption = $scope->readsEveryCompetitorOf($target) ? $this->adopter->adopt($target, $matching, $scope->windows, $now) : Adoption::Undecided;
            if ($adoption === Adoption::Adopted) {
                $adopted[] = $target->deposit->id();

                continue;
            }
            $reason = $this->reviewReason($adoption, $target, $now);
            if ($reason !== null) {
                $needingReview[] = self::reviewItem($target, $matching, $reason);
            }
        }
        $this->unitOfWork->run(fn(RecordedEvents $events): int => $this->ingestion->ingest($tenantId, $references, $now, $events));

        return ReconciliationReport::settled($markedUnknown, $adopted, $needingReview, CandidateSelection::unattached($candidates, $scope->windows, $scope->horizon));
    }

    /**
     * @return list<ReturnReference>
     *
     * @throws PollingConstraintViolated
     */
    private function list(TenantId $tenantId, Instant $from, Instant $to): array
    {
        $references = [];
        foreach (ListingWindows::split($from, $to, $this->policy->pollingMaxRange()) as [$rangeFrom, $rangeTo]) {
            $references = [...$references, ...$this->gateway->listReturns($tenantId, $rangeFrom, $rangeTo)];
        }

        return $references;
    }

    private function reviewReason(Adoption $adoption, ClaimWindow $target, Instant $now): ?string
    {
        if ($adoption === Adoption::NeedsReview) {
            return ManualReviewItem::ADOPTION_NOT_AUTOMATIC;
        }

        return $this->isOverdue($target, $now) ? ManualReviewItem::UNKNOWN_OUTCOME : null;
    }

    private function isOverdue(ClaimWindow $target, Instant $now): bool
    {
        return $target->deposit->state() === DepositState::OutcomeUnknown
            && $now->isAfter(Instants::plus($target->claimedAt, $this->policy->manualReviewAfter()));
    }

    /**
     * @param list<ReturnReference> $matching
     */
    private static function reviewItem(ClaimWindow $target, array $matching, string $reason): ManualReviewItem
    {
        return new ManualReviewItem(
            $target->deposit->id(),
            $target->deposit->externalReference(),
            $target->deposit->fingerprint(),
            $target->claimedAt,
            array_map(static fn(ReturnReference $candidate): FlowId => $candidate->flowId(), $matching),
            $reason,
        );
    }
}
