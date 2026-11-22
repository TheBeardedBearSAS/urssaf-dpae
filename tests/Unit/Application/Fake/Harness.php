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

use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnRepository;
use TheBeardedBear\UrssafDpae\Application\Service\DepositAdopter;
use TheBeardedBear\UrssafDpae\Application\Service\DepositUpdater;
use TheBeardedBear\UrssafDpae\Application\Service\FluxCandidates;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\SendSettlement;
use TheBeardedBear\UrssafDpae\Application\Service\StaleDepositSweeper;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryDepositRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnContentStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemoryReturnRepository;
use TheBeardedBear\UrssafDpae\Infrastructure\Persistence\InMemory\InMemorySyncCursorRepository;

/**
 * The application layer wired on in-memory repositories and recording doubles, with a clock fixed at `Samples::NOW`.
 * `tenant-1` is a direct declarant (service 25), `tenant-c` a concentrator (service 98).
 */
/**
 * @mago-expect lint:too-many-properties A test harness wires every collaborator of the application layer once and exposes them all
 */
final readonly class Harness
{
    public Journal $journal;
    public FixedClock $clock;
    public SequentialIdGenerator $ids;
    public RecordingEventPublisher $events;
    public JournalingOutbox $outbox;
    public RecordingFailureReporter $failures;
    public UnitOfWork $unitOfWork;
    public InMemorySyncCursorRepository $cursors;
    public InMemoryReturnContentStore $contents;
    public FakeDpaeGateway $gateway;
    public ReturnIngestion $ingestion;
    public InMemoryCredentialsProvider $credentials;

    private function __construct(public WorkflowPolicy $policy, public DepositRepository $deposits, public ReturnRepository $returns)
    {
        $this->journal = new Journal();
        $this->clock = new FixedClock(Samples::NOW);
        $this->ids = new SequentialIdGenerator();
        $this->events = new RecordingEventPublisher($this->journal);
        $this->outbox = new JournalingOutbox($this->journal);
        $this->failures = new RecordingFailureReporter();
        $this->unitOfWork = new UnitOfWork(new RecordingTransactionManager($this->journal), $this->events, $this->failures, $this->outbox);
        $this->cursors = new InMemorySyncCursorRepository();
        $this->contents = new InMemoryReturnContentStore();
        $this->gateway = new FakeDpaeGateway();
        $this->ingestion = new ReturnIngestion($this->returns, $this->deposits);
        $this->credentials = new InMemoryCredentialsProvider([
            'tenant-1' => DeclarantCredentials::of(Siret::fromString(Samples::SIRET), 'Doe', 'John', 'Example-Passw0rd', ServiceCode::direct()),
            'tenant-c' => DeclarantCredentials::of(Siret::fromString('98765432109876'), 'Doe', 'Jane', 'Example-Passw0rd', ServiceCode::concentrator()),
        ]);
    }

    /**
     * Repositories that keep the very objects they are given.
     */
    public static function inMemory(WorkflowPolicy $policy): self
    {
        return new self($policy, new InMemoryDepositRepository(), new InMemoryReturnRepository());
    }

    /**
     * Repositories that keep and give copies, like a database: a change that is not saved is lost.
     */
    public static function snapshotting(WorkflowPolicy $policy): self
    {
        return new self($policy, new SnapshotDepositRepository(new InMemoryDepositRepository()), new SnapshotReturnRepository(new InMemoryReturnRepository()));
    }

    public function depositHandler(DeclarationMode $installationMode): DepositDpaeHandler
    {
        return $this->depositHandlerOver($this->deposits, $installationMode);
    }

    public function depositHandlerOver(DepositRepository $deposits, DeclarationMode $installationMode): DepositDpaeHandler
    {
        return new DepositDpaeHandler($deposits, $this->gateway, $this->credentials, $this->clock, $this->ids, $this->unitOfWork, new SendSettlement($deposits, new DepositUpdater($deposits, $this->unitOfWork), $this->policy, $this->clock), $this->policy, $installationMode);
    }

    public function listHandler(): ListReturnsHandler
    {
        return new ListReturnsHandler($this->gateway, $this->ingestion, $this->clock, $this->unitOfWork);
    }

    public function syncHandler(): SynchronizeReturnsHandler
    {
        return new SynchronizeReturnsHandler($this->gateway, $this->ingestion, $this->cursors, $this->clock, $this->unitOfWork, $this->policy);
    }

    public function downloadHandler(): DownloadReturnHandler
    {
        return new DownloadReturnHandler($this->returns, $this->contents, $this->gateway, $this->clock, $this->unitOfWork);
    }

    public function reconcileHandler(): ReconcileUnknownDepositsHandler
    {
        return $this->reconcileHandlerOver($this->deposits);
    }

    public function reconcileHandlerOver(DepositRepository $deposits): ReconcileUnknownDepositsHandler
    {
        $updater = new DepositUpdater($deposits, $this->unitOfWork);

        return new ReconcileUnknownDepositsHandler(
            $deposits,
            $this->gateway,
            new StaleDepositSweeper($deposits, $updater, $this->policy),
            new FluxCandidates($deposits),
            new DepositAdopter($updater, $this->policy),
            $this->ingestion,
            $this->clock,
            $this->unitOfWork,
            $this->policy,
        );
    }
}
