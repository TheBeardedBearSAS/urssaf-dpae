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
 * Behaves like a database: what is saved is a copy, and what is read is a copy, so a change that is not saved is lost.
 * Catches a handler that forgets to save, which the by-reference in-memory repository hides.
 */
final readonly class SnapshotDepositRepository implements DepositRepository
{
    public function __construct(private DepositRepository $store) {}

    public function save(Deposit $deposit): void
    {
        $this->store->save(clone $deposit);
        $deposit->advanceVersion();
    }

    public function get(DepositId $id): Deposit
    {
        return clone $this->store->get($id);
    }

    public function findOpenByReference(TenantId $tenantId, ExternalReference $reference): ?Deposit
    {
        return self::copy($this->store->findOpenByReference($tenantId, $reference));
    }

    public function findOpenByFingerprint(TenantId $tenantId, FlowFingerprint $fingerprint): ?Deposit
    {
        return self::copy($this->store->findOpenByFingerprint($tenantId, $fingerprint));
    }

    public function findByFlowId(TenantId $tenantId, FlowId $flowId): ?Deposit
    {
        return self::copy($this->store->findByFlowId($tenantId, $flowId));
    }

    public function claim(DepositId $id, Instant $now, int $maxAttempts): bool
    {
        return $this->store->claim($id, $now, $maxAttempts);
    }

    public function withoutFlowId(TenantId $tenantId, int $limit): array
    {
        return array_map(static fn(Deposit $deposit): Deposit => clone $deposit, $this->store->withoutFlowId($tenantId, $limit));
    }

    public function staleDepositing(TenantId $tenantId, Instant $claimedBefore, int $limit): array
    {
        return array_map(static fn(Deposit $deposit): Deposit => clone $deposit, $this->store->staleDepositing($tenantId, $claimedBefore, $limit));
    }

    private static function copy(?Deposit $deposit): ?Deposit
    {
        return $deposit instanceof Deposit ? clone $deposit : null;
    }
}
