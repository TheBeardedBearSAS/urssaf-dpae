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

use DateInterval;
use TheBeardedBear\UrssafDpae\Application\Dto\SynchronizationReport;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Service\Instants;
use TheBeardedBear\UrssafDpae\Application\Service\ListingWindows;
use TheBeardedBear\UrssafDpae\Application\Service\RecordedEvents;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;

/**
 * Synchronises the returns of one tenant (ADR 0006): lists from the cursor, upserts, links to deposits, and moves the
 * cursor to the instant the run started minus `pollingOverlap` only once the whole answer is stored. A polling
 * refusal (422 / 429) stops the run without moving the cursor and is reported, not thrown: a batch keeps going.
 */
final readonly class SynchronizeReturnsHandler
{
    public function __construct(
        private DpaeGateway $gateway,
        private ReturnIngestion $ingestion,
        private SyncCursorRepository $cursors,
        private Clock $clock,
        private UnitOfWork $unitOfWork,
        private WorkflowPolicy $policy,
    ) {}

    public function __invoke(SynchronizeReturns $command): SynchronizationReport
    {
        $tenantId = $command->tenantId;
        $started = Instant::fromDateTime($this->clock->now());
        $cursor = $this->cursors->find($tenantId) ?? SyncCursor::initial($tenantId);
        $discovered = 0;
        $windows = 0;

        try {
            foreach ($this->windows($this->origin($cursor, $started), $started) as [$from, $to]) {
                $references = $this->gateway->listReturns($tenantId, $from, $to);
                $discovered += $this->unitOfWork->run(
                    fn(RecordedEvents $events): int => $this->ingestion->ingest($tenantId, $references, $started, $events),
                );
                ++$windows;
            }
        } catch (PollingConstraintViolated $refusal) {
            $refused = $cursor->refused();
            $this->cursors->save($refused);

            return SynchronizationReport::refused(
                $discovered,
                $windows,
                $refused->consecutiveRefusals() >= $this->policy->maxPollingConstraintRetries(),
                $refusal->retryAfterSeconds() ?? $this->policy->pollingRetryDelaySeconds(),
            );
        }
        $this->cursors->save($cursor->advancedTo(Instants::minus($started, $this->policy->pollingOverlap()), $started));

        return SynchronizationReport::completed($discovered, $windows);
    }

    /**
     * The cursor, or the initial lookback on a first run, never older than the 13 months URSSAF keeps and never in the future.
     */
    private function origin(SyncCursor $cursor, Instant $started): Instant
    {
        $origin = $cursor->position() ?? Instants::minus($started, $this->policy->initialLookback());
        $floor = Instants::minus($started, new DateInterval(WorkflowPolicy::MAX_LOOKBACK));
        if ($origin->isBefore($floor)) {
            return $floor;
        }

        return $origin->isAfter($started) ? $started : $origin;
    }

    /**
     * The one-date path from the origin, or consecutive two-date windows of at most `pollingMaxRange`.
     *
     * @return list<array{Instant, ?Instant}>
     */
    private function windows(Instant $origin, Instant $until): array
    {
        $range = $this->policy->pollingMaxRange();
        if (!$range instanceof DateInterval) {
            return [[$origin, null]];
        }

        return ListingWindows::split($origin, $until, $range);
    }
}
