<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Testing;

use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\FixedClock;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class SimulatedDpaeGatewayTest extends TestCase
{
    use SimulatedGatewayFixtures;

    public function testATestFlowIsAnsweredWithAReceiptAndRecordedAsAFlux(): void
    {
        $clock = new FixedClock(Samples::NOW);
        $gateway = self::gateway($clock);
        $flow = Samples::flow(DeclarationMode::Test, 'a');

        $result = $gateway->deposit(Samples::tenant('tenant-1'), $flow);

        static::assertInstanceOf(ElectronicReceipt::class, $result);
        static::assertSame('id-1', $result->flowId()?->value());
        static::assertSame(Samples::NOW, $result->generatedAt()?->toIso8601());
        static::assertSame('application/xml', $result->document()->contentType());
        static::assertStringContainsString('<IdFluxDPAE>id-1</IdFluxDPAE>', $result->document()->bytes());
        static::assertSame([Operation::Deposit], $gateway->calls());
        static::assertCount(1, $gateway->deposited());
        static::assertTrue($gateway->deposited()[0]->equals($flow->fingerprint()));
    }

    public function testARealFlowIsAlwaysRefused(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));

        try {
            $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Real, 'a'));
            static::fail('LogicException expected.');
        } catch (LogicException $e) {
            static::assertStringContainsString('never accepts a real flow', $e->getMessage());
        }

        static::assertSame([], $gateway->deposited());
        static::assertSame([], $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null));
    }

    public function testARealFlowIsRefusedBeforeItIsJournaledAndBeforeAScriptedFailureIsUsed(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $scripted = new RuntimeException('scripted');
        $gateway->failNext(Operation::Deposit, $scripted);
        $gateway->rejectNextDeposit(['01']);

        $refusal = Outcome::of(static fn(): DepositResult => $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Real, 'a')));

        static::assertInstanceOf(LogicException::class, $refusal);
        static::assertSame([], $gateway->calls());
        $next = Outcome::of(static fn(): DepositResult => $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a')));
        static::assertSame($scripted, $next);
        static::assertInstanceOf(RejectionNotice::class, $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'b')));
    }

    public function testARejectionCanBeScriptedOnce(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->rejectNextDeposit(['01', '02']);

        $first = $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
        $second = $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'b'));

        static::assertInstanceOf(RejectionNotice::class, $first);
        static::assertSame(['01', '02'], $first->reasons());
        static::assertInstanceOf(ElectronicReceipt::class, $second);
        $listed = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null);
        static::assertSame(['KO', 'OK'], array_map(static fn(ReturnReference $return): string => $return->statusCode(), $listed));
    }

    public function testALostAnswerStillRecordsTheFluxForTheReconciliation(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $gateway->loseNextAnswer();

        try {
            $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
            static::fail('OutcomeUnknown expected.');
        } catch (OutcomeUnknown $e) {
            static::assertTrue($e->mayHaveReachedUrssaf());
        }
        $next = $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'b'));

        static::assertInstanceOf(ElectronicReceipt::class, $next);
        $listed = $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null);
        static::assertSame(['id-1', 'id-2'], array_map(static fn(ReturnReference $return): string => $return->flowId()->value(), $listed));
    }

    public function testEveryOperationCanBeScriptedToFailOnce(): void
    {
        $gateway = self::gateway(new FixedClock(Samples::NOW));
        $failure = new RuntimeException('scripted');
        $gateway->failNext(Operation::Deposit, $failure);
        $gateway->failNext(Operation::ListReturns, $failure);

        try {
            $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a'));
            static::fail('RuntimeException expected.');
        } catch (RuntimeException $e) {
            static::assertSame($failure, $e);
        }
        try {
            $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null);
            static::fail('RuntimeException expected.');
        } catch (RuntimeException $e) {
            static::assertSame($failure, $e);
        }

        static::assertSame([], $gateway->listReturns(Samples::tenant('tenant-1'), Samples::at('2022-01-01T00:00:00Z'), null));
        static::assertInstanceOf(ElectronicReceipt::class, $gateway->deposit(Samples::tenant('tenant-1'), Samples::flow(DeclarationMode::Test, 'a')));
        static::assertSame([Operation::Deposit, Operation::ListReturns, Operation::ListReturns, Operation::Deposit], $gateway->calls());
        static::assertCount(1, $gateway->deposited());
    }
}
