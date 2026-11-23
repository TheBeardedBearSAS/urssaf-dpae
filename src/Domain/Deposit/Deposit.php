<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Deposit;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositAdopted;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositFailed;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositOutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositParked;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositRejected;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidStateTransition;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * One deposit of a DPAE flow and its lifecycle (ADR 0005). The aggregate holds no flow bytes: the fingerprint and the
 * result document are enough to reconcile (ADR 0008). Every transition records the domain events it implies.
 *
 * Not `readonly`: it is the mutable aggregate root persisted by a repository of the application layer.
 *
 * @mago-expect lint:too-many-methods One method per edge of the ADR 0005 state diagram, plus the accessors the repositories and the reconciliation read
 */
final class Deposit
{
    private const AGGREGATE = 'deposit';

    private DepositState $state = DepositState::Pending;

    private int $attempts = 0;

    private int $version = 0;

    private bool $adoptedByReconciliation = false;

    /**
     * @var list<DomainEvent>
     */
    private array $events = [];

    private function __construct(
        private readonly DepositId $id,
        private readonly TenantId $tenantId,
        private readonly ExternalReference $externalReference,
        private readonly FlowFingerprint $fingerprint,
        private readonly DeclarationMode $mode,
        private readonly ?Siret $declarantSiret,
        private Instant $updatedAt,
        private Instant $claimedAt,
        private ?FlowId $flowId,
        private ?DepositResult $result,
        private ?string $lastErrorCode,
    ) {}

    /**
     * A new deposit, `pending`. `$declarantSiret` is the tenant's SIRET for a direct identity and null when it is not
     * known (a concentrator's flow carries it, ADR 0005).
     *
     * @mago-expect lint:excessive-parameter-list One parameter per immutable fact of the deposit; the constructor limit (12) applies to this named constructor, which only forwards them
     */
    public static function request(
        DepositId $id,
        TenantId $tenantId,
        ExternalReference $externalReference,
        FlowFingerprint $fingerprint,
        DeclarationMode $mode,
        ?Siret $declarantSiret,
        Instant $now,
    ): self {
        return new self($id, $tenantId, $externalReference, $fingerprint, $mode, $declarantSiret, $now, $now, null, null, null);
    }

    public function id(): DepositId
    {
        return $this->id;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }

    public function externalReference(): ExternalReference
    {
        return $this->externalReference;
    }

    public function fingerprint(): FlowFingerprint
    {
        return $this->fingerprint;
    }

    public function mode(): DeclarationMode
    {
        return $this->mode;
    }

    public function declarantSiret(): ?Siret
    {
        return $this->declarantSiret;
    }

    public function state(): DepositState
    {
        return $this->state;
    }

    /**
     * Number of claims: each `pending` to `depositing` move counts one, whatever came of it.
     */
    public function attempts(): int
    {
        return $this->attempts;
    }

    /**
     * When the last claim was made, the origin of the reconciliation window (ADR 0005). The creation instant until the
     * first claim: it only means something once {@see self::attempts()} is positive.
     */
    public function claimedAt(): Instant
    {
        return $this->claimedAt;
    }

    /**
     * The optimistic-lock version this aggregate was read at. A repository refuses (`deposit.concurrent_modification`)
     * to save an aggregate whose version is not the stored one, and moves it on with {@see self::advanceVersion()}.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * For repositories only: the aggregate was saved, the stored version is one more.
     */
    public function advanceVersion(): void
    {
        ++$this->version;
    }

    /**
     * Whether the flow id (and, for an unknown outcome, the final state) was inferred by the reconciliation rather than
     * answered by URSSAF to the deposit (ADR 0005).
     */
    public function adoptedByReconciliation(): bool
    {
        return $this->adoptedByReconciliation;
    }

    public function updatedAt(): Instant
    {
        return $this->updatedAt;
    }

    /**
     * `IdFluxDPAE`, once known (from the receipt or by reconciliation).
     */
    public function flowId(): ?FlowId
    {
        return $this->flowId;
    }

    public function result(): ?DepositResult
    {
        return $this->result;
    }

    /**
     * Stable code of the last problem (`deposit.*`), never a message nor a transport detail.
     */
    public function lastErrorCode(): ?string
    {
        return $this->lastErrorCode;
    }

    public function isOpen(): bool
    {
        return $this->state->isOpen();
    }

    /**
     * Claims the deposit for sending: `pending` to `depositing`, only while fewer than `$maxAttempts` claims were made.
     *
     * @throws InvalidStateTransition
     * @throws InvariantViolation     `deposit.attempts_exhausted`
     */
    public function startDeposit(Instant $now, int $maxAttempts): void
    {
        if ($this->attempts >= $maxAttempts) {
            throw InvariantViolation::because('deposit.attempts_exhausted', 'The deposit has no attempt left.', ['attempts' => $this->attempts, 'max_attempts' => $maxAttempts]);
        }
        $this->moveTo(DepositState::Depositing, $now, DepositState::Pending);
        ++$this->attempts;
        $this->claimedAt = $now;
    }

    /**
     * URSSAF answered: an electronic receipt (`received`) or a rejection notice (`rejected`). The answer may come late,
     * after a sweep moved the deposit to `outcome_unknown`: it then settles it.
     *
     * @throws InvalidStateTransition
     */
    public function recordResult(DepositResult $result, Instant $now): void
    {
        $rejected = $result instanceof RejectionNotice;
        $this->moveTo($rejected ? DepositState::Rejected : DepositState::Received, $now, DepositState::Depositing, DepositState::OutcomeUnknown);
        $this->result = $result;
        $this->flowId = $result->flowId();
        $this->events[] = $rejected
            ? new DepositRejected($this->id, $this->tenantId, $this->externalReference, $this->flowId, $now)
            : new DepositReceived($this->id, $this->tenantId, $this->externalReference, $this->flowId, $now);
    }

    /**
     * The flow provably did not reach URSSAF: back to `pending`, or `failed` once `$maxAttempts` claims were made.
     *
     * @throws InvalidStateTransition
     * @throws InvariantViolation     `deposit.invalid_max_attempts` when `$maxAttempts` is below 1
     */
    public function markNotSent(string $errorCode, int $maxAttempts, Instant $now): void
    {
        if ($maxAttempts < 1) {
            throw InvariantViolation::because('deposit.invalid_max_attempts', 'The maximum number of attempts must be positive.', ['max_attempts' => $maxAttempts]);
        }
        $exhausted = $this->attempts >= $maxAttempts;
        $this->moveTo($exhausted ? DepositState::Failed : DepositState::Pending, $now, DepositState::Depositing);
        $this->lastErrorCode = $errorCode;
        if ($exhausted) {
            $this->events[] = new DepositFailed($this->id, $this->tenantId, $this->externalReference, $errorCode, $now);
        }
    }

    /**
     * URSSAF refused the request without processing it (HTTP 400, 404, 406, 415, 3xx): closed, never retried by the package.
     *
     * @throws InvalidStateTransition
     */
    public function markFailed(string $errorCode, Instant $now): void
    {
        $this->moveTo(DepositState::Failed, $now, DepositState::Depositing);
        $this->lastErrorCode = $errorCode;
        $this->events[] = new DepositFailed($this->id, $this->tenantId, $this->externalReference, $errorCode, $now);
    }

    /**
     * URSSAF refused the credentials: parked until {@see self::requeue()}.
     *
     * @throws InvalidStateTransition
     */
    public function markInvalidCredentials(Instant $now): void
    {
        $this->moveTo(DepositState::InvalidCredentials, $now, DepositState::Depositing);
        $this->lastErrorCode = 'deposit.invalid_credentials';
        $this->events[] = new DepositParked($this->id, $this->tenantId, $this->externalReference, $this->lastErrorCode, $now);
    }

    /**
     * The flow may have been received but the answer was lost: never resent, to be reconciled.
     *
     * @throws InvalidStateTransition
     */
    public function markOutcomeUnknown(string $errorCode, Instant $now): void
    {
        $this->moveTo(DepositState::OutcomeUnknown, $now, DepositState::Depositing);
        $this->lastErrorCode = $errorCode;
        $this->events[] = new DepositOutcomeUnknown($this->id, $this->tenantId, $this->externalReference, $errorCode, $now);
    }

    /**
     * Credentials fixed: `invalid_credentials` back to `pending`, with a fresh attempt budget: the refusal said nothing
     * about the flow, and the claim that met it counted an attempt.
     *
     * @throws InvalidStateTransition
     */
    public function requeue(Instant $now): void
    {
        $this->moveTo(DepositState::Pending, $now, DepositState::InvalidCredentials);
        $this->attempts = 0;
    }

    /**
     * An unknown outcome found to be a receipt (reconciliation or operator).
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsReceived(FlowId $flowId, Instant $now): void
    {
        $this->moveTo(DepositState::Received, $now, DepositState::OutcomeUnknown);
        $this->flowId = $flowId;
        $this->events[] = new DepositReceived($this->id, $this->tenantId, $this->externalReference, $flowId, $now);
    }

    /**
     * An unknown outcome found to be a rejection notice (reconciliation or operator).
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsRejected(FlowId $flowId, Instant $now): void
    {
        $this->moveTo(DepositState::Rejected, $now, DepositState::OutcomeUnknown);
        $this->flowId = $flowId;
        $this->events[] = new DepositRejected($this->id, $this->tenantId, $this->externalReference, $flowId, $now);
    }

    /**
     * An operator established that URSSAF never received the flow: closed, the host may deposit it again.
     *
     * @throws InvalidStateTransition
     */
    public function resolveAsNotReceived(Instant $now): void
    {
        $this->moveTo(DepositState::NotReceived, $now, DepositState::OutcomeUnknown);
    }

    /**
     * The reconciliation matched a flux to an unknown outcome and found a receipt: {@see self::resolveAsReceived()},
     * marked as adopted.
     *
     * @throws InvalidStateTransition
     */
    public function adoptAsReceived(FlowId $flowId, Instant $now): void
    {
        $this->resolveAsReceived($flowId, $now);
        $this->markAdopted($flowId, $now);
    }

    /**
     * The reconciliation matched a flux to an unknown outcome and found a rejection notice:
     * {@see self::resolveAsRejected()}, marked as adopted.
     *
     * @throws InvalidStateTransition
     */
    public function adoptAsRejected(FlowId $flowId, Instant $now): void
    {
        $this->resolveAsRejected($flowId, $now);
        $this->markAdopted($flowId, $now);
    }

    /**
     * A `received` or `rejected` deposit whose answer carried no readable `IdFluxDPAE` takes the flow id found by
     * reconciliation, marked as adopted. The state is kept.
     *
     * @throws InvalidStateTransition
     * @throws InvariantViolation     `deposit.flow_id_already_known`
     */
    public function gainFlowId(FlowId $flowId, Instant $now): void
    {
        if ($this->state !== DepositState::Received && $this->state !== DepositState::Rejected) {
            throw InvalidStateTransition::between(self::AGGREGATE, $this->state->value, 'flow_id_known');
        }
        if ($this->flowId instanceof FlowId) {
            throw InvariantViolation::because('deposit.flow_id_already_known', 'The deposit already has a flow id.');
        }
        $this->flowId = $flowId;
        $this->updatedAt = $now;
        $this->markAdopted($flowId, $now);
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    private function markAdopted(FlowId $flowId, Instant $now): void
    {
        $this->adoptedByReconciliation = true;
        $this->events[] = new DepositAdopted($this->id, $this->tenantId, $this->externalReference, $flowId, $this->state, $now);
    }

    private function moveTo(DepositState $to, Instant $now, DepositState ...$from): void
    {
        if (!\in_array($this->state, $from, true)) {
            throw InvalidStateTransition::between(self::AGGREGATE, $this->state->value, $to->value);
        }
        $this->state = $to;
        $this->updatedAt = $now;
    }
}
