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

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * A repository in which another worker always wins the claim: the claim is made, then reported as lost.
 */
final readonly class LosingClaimDepositRepository implements DepositRepository
{
    public function __construct(private DepositRepository $inner) {}

    public function save(Deposit $deposit): void
    {
        $this->inner->save($deposit);
    }

    public function claim(DepositId $id, Instant $now, int $maxAttempts): bool
    {
        $this->inner->claim($id, $now, $maxAttempts);

        return false;
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
