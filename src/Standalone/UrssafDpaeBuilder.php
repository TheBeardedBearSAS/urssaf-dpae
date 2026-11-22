<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Standalone;

use Closure;
use LogicException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturnHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDepositsHandler;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturnsHandler;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Clock;
use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\EventPublisher;
use TheBeardedBear\UrssafDpae\Application\Port\IdGenerator;
use TheBeardedBear\UrssafDpae\Application\Port\TokenStore;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Service\DepositAdopter;
use TheBeardedBear\UrssafDpae\Application\Service\DepositUpdater;
use TheBeardedBear\UrssafDpae\Application\Service\FluxCandidates;
use TheBeardedBear\UrssafDpae\Application\Service\IgnoredFailures;
use TheBeardedBear\UrssafDpae\Application\Service\ReturnIngestion;
use TheBeardedBear\UrssafDpae\Application\Service\SendSettlement;
use TheBeardedBear\UrssafDpae\Application\Service\StaleDepositSweeper;
use TheBeardedBear\UrssafDpae\Application\Service\UnitOfWork;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\PsrClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Clock\SystemClock;
use TheBeardedBear\UrssafDpae\Infrastructure\Event\NullEventPublisher;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\RandomIdGenerator;
use TheBeardedBear\UrssafDpae\Infrastructure\Id\SecureRandomBytes;
use TheBeardedBear\UrssafDpae\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\GatewayOptions;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafGatewayFactory;

/**
 * Builds {@see UrssafDpae} outside a framework. The defaults keep everything in memory (repositories, token, outbox,
 * events): enough to try the package, not for the full lifecycle, which needs durable repositories (ADR 0008).
 *
 * The installation's `declaration_mode` is the one of the {@see ApiConfiguration} (default `test`, never defaulted to
 * `real`): it caps the mode of every flow deposited ({@see DepositDpaeHandler}), and a gateway given with
 * {@see self::withGateway()} is refused when it is `real` (ADR 0003, 0009): only the HTTP gateway may talk in a real
 * installation.
 *
 * @mago-expect lint:too-many-methods A fluent builder: one `with*` per collaborator, each returning a modified copy
 */
final class UrssafDpaeBuilder
{
    private ?DpaeGateway $gateway = null;
    private TokenStore $tokenStore;
    private ClockInterface $clock;
    private IdGenerator $ids;
    private EventPublisher $events;
    private ?LoggerInterface $logger = null;
    private WorkflowPolicy $policy;
    private Persistence $persistence;

    private function __construct(
        private readonly HttpTransport $transport,
        private readonly CredentialsProvider $credentials,
        private readonly ApiConfiguration $configuration,
    ) {
        $this->tokenStore = new InMemoryTokenStore();
        $this->clock = new SystemClock();
        $this->ids = new RandomIdGenerator(new SecureRandomBytes());
        $this->events = new NullEventPublisher();
        $this->policy = WorkflowPolicy::defaults();
        $this->persistence = Persistence::inMemory();
    }

    public static function create(HttpTransport $transport, CredentialsProvider $credentials, ApiConfiguration $configuration): self
    {
        return new self($transport, $credentials, $configuration);
    }

    public function withTokenStore(TokenStore $tokenStore): self
    {
        return $this->with(static function (self $builder) use ($tokenStore): void {
            $builder->tokenStore = $tokenStore;
        });
    }

    public function withClock(ClockInterface $clock): self
    {
        return $this->with(static function (self $builder) use ($clock): void {
            $builder->clock = $clock;
        });
    }

    public function withIdGenerator(IdGenerator $ids): self
    {
        return $this->with(static function (self $builder) use ($ids): void {
            $builder->ids = $ids;
        });
    }

    public function withEventPublisher(EventPublisher $events): self
    {
        return $this->with(static function (self $builder) use ($events): void {
            $builder->events = $events;
        });
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return $this->with(static function (self $builder) use ($logger): void {
            $builder->logger = $logger;
        });
    }

    public function withPolicy(WorkflowPolicy $policy): self
    {
        return $this->with(static function (self $builder) use ($policy): void {
            $builder->policy = $policy;
        });
    }

    /**
     * Replaces the in-memory persistence, as a whole (the ports must share one store and one transaction).
     */
    public function withPersistence(Persistence $persistence): self
    {
        return $this->with(static function (self $builder) use ($persistence): void {
            $builder->persistence = $persistence;
        });
    }

    /**
     * Replaces the HTTP gateway, for example by a simulated or a faked one.
     *
     * @throws LogicException when the installation's `declaration_mode` is `real`
     */
    public function withGateway(DpaeGateway $gateway): self
    {
        if ($this->configuration->declarationMode() === DeclarationMode::Real) {
            throw new LogicException('A custom gateway cannot be used with the real declaration mode: only the HTTP gateway may talk in a real installation.');
        }

        return $this->with(static function (self $builder) use ($gateway): void {
            $builder->gateway = $gateway;
        });
    }

    public function build(): UrssafDpae
    {
        $gateway = $this->gateway ?? $this->httpGateway();
        $clock = new PsrClock($this->clock);
        $unitOfWork = $this->unitOfWork();
        $ingestion = new ReturnIngestion($this->persistence->returns, $this->persistence->deposits);

        return new UrssafDpae(
            $gateway,
            $this->depositor($gateway, $clock, $unitOfWork),
            new ListReturnsHandler($gateway, $ingestion, $clock, $unitOfWork),
            new SynchronizeReturnsHandler($gateway, $ingestion, $this->persistence->cursors, $clock, $unitOfWork, $this->policy),
            new DownloadReturnHandler($this->persistence->returns, $this->persistence->contents, $gateway, $clock, $unitOfWork),
            $this->reconciler($gateway, $ingestion, $clock, $unitOfWork),
        );
    }

    /**
     * @param Closure(self): void $change
     */
    private function with(Closure $change): self
    {
        $builder = clone $this;
        $change($builder);

        return $builder;
    }

    private function httpGateway(): DpaeGateway
    {
        $options = GatewayOptions::defaults()->withClock($this->clock);

        return UrssafGatewayFactory::createWith(
            $this->transport,
            $this->credentials,
            $this->tokenStore,
            $this->configuration,
            $this->logger instanceof LoggerInterface ? $options->withLogger($this->logger) : $options,
        );
    }

    private function reconciler(DpaeGateway $gateway, ReturnIngestion $ingestion, Clock $clock, UnitOfWork $unitOfWork): ReconcileUnknownDepositsHandler
    {
        $deposits = $this->persistence->deposits;
        $updater = new DepositUpdater($deposits, $unitOfWork);

        return new ReconcileUnknownDepositsHandler(
            $deposits,
            $gateway,
            new StaleDepositSweeper($deposits, $updater, $this->policy),
            new FluxCandidates($deposits),
            new DepositAdopter($updater, $this->policy),
            $ingestion,
            $clock,
            $unitOfWork,
            $this->policy,
        );
    }

    private function unitOfWork(): UnitOfWork
    {
        $failures = $this->logger instanceof LoggerInterface ? new PsrLoggerFailureReporter($this->logger) : new IgnoredFailures();

        return new UnitOfWork($this->persistence->transactions, $this->events, $failures, $this->persistence->outbox);
    }

    private function depositor(DpaeGateway $gateway, Clock $clock, UnitOfWork $unitOfWork): DepositDpaeHandler
    {
        return new DepositDpaeHandler(
            $this->persistence->deposits,
            $gateway,
            $this->credentials,
            $clock,
            $this->ids,
            $unitOfWork,
            new SendSettlement($this->persistence->deposits, new DepositUpdater($this->persistence->deposits, $unitOfWork), $this->policy, $clock),
            $this->policy,
            $this->configuration->declarationMode(),
        );
    }
}
