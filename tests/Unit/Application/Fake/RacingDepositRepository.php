<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use Closure;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * A repository in which another worker changes the stored deposit right before a save, so that the save meets a newer
 * version (`deposit.concurrent_modification`): once or every time, from the next save or from the first one after a claim
 * (the answer of a send).
 */
final class RacingDepositRepository implements DepositRepository
{
    private bool $armed;

    private bool $interfered = false;

    /**
     * @param Closure(DepositRepository, DepositId): void $interference run against the inner repository, with the id of the deposit about to be saved
     */
    public function __construct(
        private readonly DepositRepository $inner,
        private readonly Closure $interference,
        private readonly RaceTiming $timing,
    ) {
        $this->armed = $timing === RaceTiming::NextSave;
    }

    public function save(Deposit $deposit): void
    {
        if ($this->armed && ($this->timing === RaceTiming::EveryTimeAfterClaim || !$this->interfered)) {
            $this->interfered = true;
            ($this->interference)($this->inner, $deposit->id());
        }
        $this->inner->save($deposit);
    }

    public function claim(DepositId $id, Instant $now, int $maxAttempts): bool
    {
        $claimed = $this->inner->claim($id, $now, $maxAttempts);
        $this->armed = true;

        return $claimed;
    }

    public function get(DepositId $id): Deposit
    {
        return $this->inner->get($id);
    }

    public function findOpenByReference(TenantId $tenantId, ExternalReference $reference): ?Deposit
    {
        return $this->inner->findOpenByReference($tenantId, $reference);
    }

    public function findOpenByFingerprint(TenantId $tenantId, FlowFingerprint $fingerprint): ?Deposit
    {
        return $this->inner->findOpenByFingerprint($tenantId, $fingerprint);
    }

    public function findByFlowId(TenantId $tenantId, FlowId $flowId): ?Deposit
    {
        return $this->inner->findByFlowId($tenantId, $flowId);
    }

    public function withoutFlowId(TenantId $tenantId, int $limit): array
    {
        return $this->inner->withoutFlowId($tenantId, $limit);
    }

    public function staleDepositing(TenantId $tenantId, Instant $claimedBefore, int $limit): array
    {
        return $this->inner->staleDepositing($tenantId, $claimedBefore, $limit);
    }
}
