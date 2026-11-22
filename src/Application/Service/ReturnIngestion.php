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

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Upserts listed returns under their identity and links each one to the local deposit with the same `flux.id`
 * (ADR 0006). Shared by the synchronisation, the manual listing and the reconciliation, none of which this class lets
 * move the cursor.
 */
final readonly class ReturnIngestion
{
    public function __construct(
        private ReturnRepository $returns,
        private DepositRepository $deposits,
    ) {}

    /**
     * Must run inside a unit of work: the recorded events leave with it.
     *
     * @param list<ReturnReference> $references
     *
     * @return int the number of returns seen for the first time
     */
    public function ingest(TenantId $tenantId, array $references, Instant $now, RecordedEvents $events): int
    {
        $discovered = 0;
        foreach ($references as $reference) {
            $deposit = $this->deposits->findByFlowId($tenantId, $reference->flowId());
            $tracked = $this->returns->find($tenantId, $reference->identity());
            if (!$tracked instanceof TrackedReturn) {
                $this->store(TrackedReturn::discover($tenantId, $reference, $deposit?->id(), $now), $events);
                ++$discovered;

                continue;
            }
            if ($deposit instanceof Deposit) {
                $tracked->linkTo($deposit->id());
            }
            $tracked->refresh($reference, $now);
            $this->store($tracked, $events);
        }

        return $discovered;
    }

    private function store(TrackedReturn $tracked, RecordedEvents $events): void
    {
        $events->record(...$tracked->releaseEvents());
        $this->returns->save($tracked);
    }
}
