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

use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Service\SendSettlement;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceKind;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Exception\DomainException;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Exception\RetryableException;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use Throwable;

/**
 * Deposits one flow (ADR 0003, 0005). Idempotent per tenant: called again with the reference of an open deposit it
 * returns that deposit and sends nothing, unless the deposit is still `pending` (it never reached URSSAF), in which
 * case it is sent again.
 *
 * Order, which is the safety of the use case: the installation ceiling is checked, the deposit is recorded and
 * committed, the claim (`pending` to `depositing`) is atomic, and only the claimant calls the gateway. Once the flow
 * may have reached URSSAF it is never resent: the deposit goes to `outcome_unknown` for the reconciliation.
 */
final readonly class DepositDpaeHandler
{
    /**
     * @param DeclarationMode $installationMode the highest mode this installation may deposit (ADR 0003)
     */
    public function __construct(
        private DepositRepository $deposits,
        private DpaeGateway $gateway,
        private CredentialsProvider $credentials,
        private Clock $clock,
        private IdGenerator $ids,
        private UnitOfWork $unitOfWork,
        private SendSettlement $settlement,
        private WorkflowPolicy $policy,
        private DeclarationMode $installationMode,
    ) {}

    /**
     * @return Deposit the deposit in the state this call left it in: `received`, `rejected`, `outcome_unknown`,
     *                 `failed`, `invalid_credentials`, or an existing deposit that another call owns
     *
     * @throws DomainException    `declaration_mode.not_allowed`, `deposit.reference_conflict`, `deposit.flow_already_open`
     *                            (nothing recorded), a local refusal of the gateway before the send (the deposit is handled
     *                            as not sent), or `deposit.concurrent_modification` when the deposit kept changing under
     *                            the answer (three reloads: reconcile it, never resend)
     * @throws RetryableException the flow provably did not reach URSSAF: the deposit is `pending` again (or `failed`
     *                            at the attempt limit)
     */
    public function __invoke(DepositDpae $command): Deposit
    {
        $flowMode = $command->flow->mode();
        if (!$this->installationMode->allows($flowMode)) {
            throw InvariantViolation::because(
                'declaration_mode.not_allowed',
                'The flow is deposited in a mode the installation does not allow.',
                ['mode' => $flowMode->value, 'installation_mode' => $this->installationMode->value],
            );
        }

        $deposit = $this->unitOfWork->run(fn(): Deposit => $this->open($command));
        if (!$this->deposits->claim($deposit->id(), $this->now(), $this->policy->maxDepositAttempts())) {
            return $this->deposits->get($deposit->id());
        }

        return $this->send($this->deposits->get($deposit->id()), $command);
    }

    private function open(DepositDpae $command): Deposit
    {
        $existing = $this->deposits->findOpenByReference($command->tenantId, $command->externalReference);
        if ($existing instanceof Deposit) {
            if (!$existing->fingerprint()->equals($command->flow->fingerprint())) {
                throw InvariantViolation::because('deposit.reference_conflict', 'The reference belongs to an open deposit of another flow.');
            }

            return $existing;
        }
        if ($this->deposits->findOpenByFingerprint($command->tenantId, $command->flow->fingerprint()) instanceof Deposit) {
            throw InvariantViolation::because('deposit.flow_already_open', 'The flow belongs to an open deposit under another reference.');
        }

        $credentials = $this->credentials->forTenant($command->tenantId);
        $deposit = Deposit::request(
            DepositId::fromString($this->ids->generate()),
            $command->tenantId,
            $command->externalReference,
            $command->flow->fingerprint(),
            $command->flow->mode(),
            $credentials->service()->kind() === ServiceKind::Direct ? $credentials->siret() : null,
            $this->now(),
        );
        $this->deposits->save($deposit);

        return $deposit;
    }

    private function send(Deposit $deposit, DepositDpae $command): Deposit
    {
        try {
            $result = $this->gateway->deposit($deposit->tenantId(), $command->flow);
        } catch (Throwable $failure) {
            return $this->settlement->failed($deposit, $failure);
        }

        return $this->settlement->answered($deposit, $result);
    }

    private function now(): Instant
    {
        return Instant::fromDateTime($this->clock->now());
    }
}
