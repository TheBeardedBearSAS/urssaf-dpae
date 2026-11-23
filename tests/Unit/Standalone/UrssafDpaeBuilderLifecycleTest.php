<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Standalone;

use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use TheBeardedBear\UrssafDpae\Application\Command\DownloadReturn;
use TheBeardedBear\UrssafDpae\Application\Command\ListReturns;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Application\Policy\WorkflowPolicy;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Infrastructure\Event\InMemoryEventPublisher;
use TheBeardedBear\UrssafDpae\Infrastructure\Outbox\InMemoryOutbox;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\UrssafHttpGateway;
use TheBeardedBear\UrssafDpae\Standalone\Persistence;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FailingEventPublisher;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FakeDpaeGateway;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\SequentialIdGenerator;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\HttpStack;

final class UrssafDpaeBuilderLifecycleTest extends TestCase
{
    use UrssafDpaeBuilderFixtures;

    public function testTheWholeLifecycleRunsOnTheDefaultsWithTheSimulator(): void
    {
        $simulator = self::simulated();
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))
            ->withGateway($simulator)
            ->withClock(new FixedClock(Samples::NOW))
            ->build();
        $tenant = Samples::tenant(HttpStack::TENANT);

        $deposit = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $report = $dpae->synchronizeReturns()(new SynchronizeReturns($tenant));
        $listed = $dpae->listReturns()(new ListReturns($tenant, Samples::at('2022-01-01T00:00:00Z'), null));
        $content = $dpae->downloadReturn()(new DownloadReturn($tenant, $listed[0]->identity()));
        $reconciled = $dpae->reconcileUnknownDeposits()(new ReconcileUnknownDeposits($tenant));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame(1, $report->discovered);
        static::assertCount(1, $listed);
        static::assertStringContainsString('IdFluxDPAE', $content->bytes());
        static::assertSame([], $reconciled->adopted);
    }

    public function testTheLostAnswerOfADepositIsReconciledAfterwards(): void
    {
        $simulator = self::simulated();
        $simulator->loseNextAnswer();
        $clock = new FixedClock(Samples::NOW);
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))->withGateway($simulator)->withClock($clock)->build();
        $tenant = Samples::tenant(HttpStack::TENANT);

        $deposit = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $again = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
        $clock->moveTo('2022-02-01T13:30:00Z');
        $report = $dpae->reconcileUnknownDeposits()(new ReconcileUnknownDeposits($tenant));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame($deposit, $again);
        static::assertSame([$deposit->id()], $report->adopted);
        static::assertSame('id-1', $deposit->flowId()?->value());
        static::assertCount(1, $simulator->deposited());
    }

    public function testTheCollaboratorsAreReplaceable(): void
    {
        $outbox = new InMemoryOutbox();
        $publisher = new InMemoryEventPublisher();
        $persistence = new Persistence(
            Persistence::inMemory()->deposits,
            Persistence::inMemory()->returns,
            Persistence::inMemory()->cursors,
            Persistence::inMemory()->contents,
            $outbox,
            Persistence::inMemory()->transactions,
        );
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))
            ->withGateway(self::simulated())
            ->withPersistence($persistence)
            ->withEventPublisher($publisher)
            ->withIdGenerator(new SequentialIdGenerator())
            ->withClock(new class implements ClockInterface {
                public function now(): DateTimeImmutable
                {
                    return new DateTimeImmutable('2022-02-01T12:00:00Z');
                }
            })
            ->build();

        $deposit = $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));

        static::assertSame('id-1', $deposit->id()->value());
        static::assertSame(Samples::NOW, $deposit->claimedAt()->toIso8601());
        static::assertCount(1, $publisher->published());
        $pending = $outbox->pending(10);
        static::assertCount(1, $pending);
        static::assertInstanceOf(DepositReceived::class, $pending[0]->event);
    }

    public function testThePolicyIsApplied(): void
    {
        $gateway = new FakeDpaeGateway();
        $gateway->willDeposit(PollingConstraintViolated::withStatus(429, null));
        $persistence = Persistence::inMemory();
        $policy = new WorkflowPolicy(1, new DateInterval('PT15M'), new DateInterval('PT5M'), new DateInterval('PT1H'), new DateInterval('PT6H'), new DateInterval('PT5M'), new DateInterval('P7D'), null, 60, 5, null);
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))
            ->withGateway($gateway)
            ->withPolicy($policy)
            ->withPersistence($persistence)
            ->withIdGenerator(new SequentialIdGenerator())
            ->build();

        try {
            $dpae->depositDpae()(self::command(DeclarationMode::Test, 'a'));
            static::fail('PollingConstraintViolated expected.');
        } catch (PollingConstraintViolated) {
            static::assertSame(DepositState::Failed, $persistence->deposits->get(DepositId::fromString('id-1'))->state());
        }
    }

    public function testAFailingListenerIsLoggedWithoutUndoingTheDeposit(): void
    {
        $stack = self::stack(ApiConfiguration::defaults());

        $deposit = self::builder($stack)
            ->withGateway(self::simulated())
            ->withEventPublisher(new FailingEventPublisher())
            ->withLogger($stack->logger)
            ->build()
            ->depositDpae()(self::command(DeclarationMode::Test, 'a'));

        static::assertSame(DepositState::Received, $deposit->state());
        static::assertSame('A domain event listener failed after the commit.', $stack->logger->records()[0]['message']);
    }

    public function testAFailingListenerIsSilentWithoutALogger(): void
    {
        $deposit = self::builder(self::stack(ApiConfiguration::defaults()))
            ->withGateway(self::simulated())
            ->withEventPublisher(new FailingEventPublisher())
            ->build()
            ->depositDpae()(self::command(DeclarationMode::Test, 'a'));

        static::assertSame(DepositState::Received, $deposit->state());
    }

    public function testTheBuilderIsImmutable(): void
    {
        $base = self::builder(self::stack(ApiConfiguration::defaults()));
        $simulator = self::simulated();

        $withGateway = $base->withGateway($simulator);

        static::assertNotSame($base, $withGateway);
        static::assertInstanceOf(UrssafHttpGateway::class, $base->build()->gateway());
        static::assertSame($simulator, $withGateway->build()->gateway());
    }

    public function testTheFacadeKeepsItsHandlers(): void
    {
        $dpae = self::builder(self::stack(ApiConfiguration::defaults()))->withGateway(self::simulated())->build();

        static::assertSame($dpae->depositDpae(), $dpae->depositDpae());
    }

    public function testThePersistenceIsInMemoryByDefault(): void
    {
        $first = Persistence::inMemory();
        $second = Persistence::inMemory();

        static::assertNotSame($first->deposits, $second->deposits);
        static::assertSame([], $first->outbox->pending(10));
        static::assertSame('x', $first->transactions->transactional(static fn(): string => 'x'));
    }
}
