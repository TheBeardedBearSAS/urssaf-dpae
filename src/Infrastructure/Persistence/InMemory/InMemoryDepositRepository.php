<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory;

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Process-local deposits, kept by reference (a saved aggregate is the stored one), with the stored version of each so
 * that a copy read earlier cannot overwrite a later save. Not durable and not shared between processes: the claim is
 * atomic within one process only (ADR 0008 asks a database for the full lifecycle).
 */
final class InMemoryDepositRepository implements DepositRepository
{
    private const SENT_WITHOUT_FLOW_ID = [
        DepositState::Depositing,
        DepositState::OutcomeUnknown,
        DepositState::Received,
        DepositState::Rejected,
    ];

    /**
     * @var array<string, Deposit>
     */
    private array $deposits = [];

    private readonly StoredVersions $versions;

    public function __construct()
    {
        $this->versions = new StoredVersions();
    }

    public function save(Deposit $deposit): void
    {
        $this->versions->assertCurrent($deposit);
        $this->deposits[$deposit->id()->value()] = $deposit;
        $this->versions->advance($deposit);
    }

    public function get(DepositId $id): Deposit
    {
        return $this->deposits[$id->value()] ?? throw RecordNotFound::withIdentifier('deposit', $id->value());
    }

    public function findOpenByReference(TenantId $tenantId, ExternalReference $reference): ?Deposit
    {
        return $this->select(
            static fn(Deposit $deposit): bool => $deposit->isOpen() && $deposit->tenantId()->equals($tenantId) && $deposit->externalReference()->equals($reference),
        )[0] ?? null;
    }

    public function findOpenByFingerprint(TenantId $tenantId, FlowFingerprint $fingerprint): ?Deposit
    {
        return $this->select(
            static fn(Deposit $deposit): bool => $deposit->isOpen() && $deposit->tenantId()->equals($tenantId) && $deposit->fingerprint()->equals($fingerprint),
        )[0] ?? null;
    }

    public function findByFlowId(TenantId $tenantId, FlowId $flowId): ?Deposit
    {
        return $this->select(
            static fn(Deposit $deposit): bool => $deposit->tenantId()->equals($tenantId) && $deposit->flowId()?->equals($flowId) === true,
        )[0] ?? null;
    }

    public function claim(DepositId $id, Instant $now, int $maxAttempts): bool
    {
        $deposit = $this->get($id);
        if ($deposit->state() !== DepositState::Pending || $deposit->attempts() >= $maxAttempts) {
            return false;
        }
        $deposit->startDeposit($now, $maxAttempts);
        $this->versions->advance($deposit);

        return true;
    }

    public function withoutFlowId(TenantId $tenantId, int $limit): array
    {
        return ClaimOrder::oldest($limit, ...$this->select(
            static fn(Deposit $deposit): bool => $deposit->tenantId()->equals($tenantId)
                && !$deposit->flowId() instanceof FlowId
                && \in_array($deposit->state(), self::SENT_WITHOUT_FLOW_ID, true),
        ));
    }

    public function staleDepositing(TenantId $tenantId, Instant $claimedBefore, int $limit): array
    {
        return ClaimOrder::oldest($limit, ...$this->select(
            static fn(Deposit $deposit): bool => $deposit->tenantId()->equals($tenantId)
                && $deposit->state() === DepositState::Depositing
                && $deposit->claimedAt()->isBefore($claimedBefore),
        ));
    }

    /**
     * @param callable(Deposit): bool $predicate
     *
     * @return list<Deposit>
     */
    private function select(callable $predicate): array
    {
        return array_values(array_filter($this->deposits, $predicate));
    }
}
