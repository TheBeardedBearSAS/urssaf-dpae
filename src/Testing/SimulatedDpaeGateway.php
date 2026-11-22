<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Testing;

use LogicException;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnStatus;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use Throwable;

/**
 * In-memory URSSAF DPAE for the tests of a host application (ADR 0003, 0000 section 11). It records every deposited
 * flow as a flux, answers a receipt (or a scripted rejection notice), and lists and serves the nature `10` return of
 * each flux. It never produces the returns `20` / `41`, which exist in real mode only, and it **refuses a `Real` flow**
 * with a `LogicException`, whatever the wiring. Nothing here talks to URSSAF.
 *
 * The documents are fictitious (the `envoi` schema is not published, api-reference.md section 5.9): do not parse them.
 */
final class SimulatedDpaeGateway implements DpaeGateway
{
    private const DOCUMENT_TYPE = 'application/xml';
    private const WIRE_DATE = 'YmdHis';

    /**
     * @var array<string, list<Throwable>>
     */
    private array $failures = [];

    /**
     * @var list<list<string>>
     */
    private array $rejections = [];

    private bool $loseNextAnswer = false;

    /**
     * @var list<Operation>
     */
    private array $calls = [];

    /**
     * @var list<FlowFingerprint>
     */
    private array $deposited = [];

    private readonly SimulatedFluxes $fluxes;

    public function __construct(
        private readonly Clock $clock,
        private readonly IdGenerator $ids,
    ) {
        $this->fluxes = new SimulatedFluxes();
    }

    /**
     * The next call of the operation throws the failure instead of answering.
     */
    public function failNext(Operation $operation, Throwable $failure): void
    {
        $this->failures[$operation->value][] = $failure;
    }

    /**
     * The next deposit is answered with a rejection notice carrying these raw reasons (nothing is recorded as received).
     *
     * @param list<string> $reasons
     */
    public function rejectNextDeposit(array $reasons): void
    {
        $this->rejections[] = $reasons;
    }

    /**
     * The next deposit is recorded by the simulated system, but its answer is lost: `OutcomeUnknown` is thrown. The
     * flux then shows in the returns, which is what the reconciliation looks for.
     */
    public function loseNextAnswer(): void
    {
        $this->loseNextAnswer = true;
    }

    /**
     * Purges the return of a flux, as URSSAF does after three months: its download is then a 404.
     */
    public function purge(FlowId $flowId): void
    {
        $this->fluxes->purge($flowId);
    }

    /**
     * Every call received, in order.
     *
     * @return list<Operation>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The fingerprints of the flows deposited, in order (a flow whose call was scripted to fail is not in it).
     *
     * @return list<FlowFingerprint>
     */
    public function deposited(): array
    {
        return $this->deposited;
    }

    public function deposit(TenantId $tenantId, DpaeFlow $flow): DepositResult
    {
        // The guard comes first: a real flow is refused whatever the wiring, before it is journaled or a scripted failure is used.
        if ($flow->mode() === DeclarationMode::Real) {
            throw new LogicException('The simulated gateway never accepts a real flow (ADR 0003).');
        }
        $this->receive(Operation::Deposit);

        $reasons = array_shift($this->rejections);
        $rejected = $reasons !== null;
        $flowId = FlowId::fromString($this->ids->generate());
        $now = Instant::fromDateTime($this->clock->now());
        $this->deposited[] = $flow->fingerprint();
        $document = \sprintf('<envoi><IdFluxDPAE>%s</IdFluxDPAE></envoi>', $flowId->value());
        $this->fluxes->add(new SimulatedFlux($tenantId, $flowId, (string) ($this->fluxes->count() + 1), $now, $rejected, $document));

        if ($this->loseNextAnswer) {
            $this->loseNextAnswer = false;

            throw OutcomeUnknown::afterFailure(Operation::Deposit, self::class);
        }
        $raw = RawDocument::of($document, self::DOCUMENT_TYPE);

        return $rejected ? RejectionNotice::of($flowId, null, $now, $raw, $reasons) : ElectronicReceipt::of($flowId, null, $now, $raw);
    }

    public function listReturns(TenantId $tenantId, Instant $from, ?Instant $to): array
    {
        $this->receive(Operation::ListReturns);
        if ($to instanceof Instant && $from->isAfter($to)) {
            throw InvariantViolation::because('returns.range_inverted', 'The start of the range is after its end.');
        }

        return array_map(self::reference(...), $this->fluxes->published($tenantId, $from, $to));
    }

    public function downloadReturn(TenantId $tenantId, ReturnReference $return): ReturnContent
    {
        $this->receive(Operation::DownloadReturn);
        if (!$return->hasDocument()) {
            throw ReturnNotDownloadable::withoutDocument($return);
        }
        $flux = $this->fluxes->find($return->flowId());
        if (!$flux instanceof SimulatedFlux || $return->natureCode() !== ReturnNature::ReceiptOrRejection->value) {
            throw ReturnNoLongerAvailable::forReturn($return);
        }
        if (!$flux->tenantId->equals($tenantId)) {
            throw UrssafRequestRejected::because(Operation::DownloadReturn, 403, 'return.not_owned');
        }
        if ($this->fluxes->isPurged($flux->flowId)) {
            throw ReturnNoLongerAvailable::forReturn($return);
        }

        return ReturnContent::of($flux->document, self::DOCUMENT_TYPE);
    }

    private function receive(Operation $operation): void
    {
        $this->calls[] = $operation;
        $queue = $this->failures[$operation->value] ?? [];
        $failure = array_shift($queue);
        $this->failures[$operation->value] = $queue;
        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    private static function reference(SimulatedFlux $flux): ReturnReference
    {
        $published = $flux->publishedAt->toDateTimeImmutable()->format(self::WIRE_DATE);

        return ReturnReference::fromListing(
            $flux->flowId,
            ReturnNature::ReceiptOrRejection->value,
            ($flux->rejected ? ReturnStatus::Failed : ReturnStatus::Ok)->value,
            $published,
            $published,
            $flux->publishedAt,
            $flux->publishedAt,
            $flux->returnId,
            null,
            null,
            null,
        );
    }
}
