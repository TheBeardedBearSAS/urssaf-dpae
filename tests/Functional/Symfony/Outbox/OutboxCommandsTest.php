<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Outbox;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use TheBeardedBear\UrssafDpae\Application\Port\IntegrationEventSink;
use TheBeardedBear\UrssafDpae\Application\Port\Outbox;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Event\EventDispatcherIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Outbox\MessengerIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\HeldLocks;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Output;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Scenario;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\RecordingIntegrationEventSink;

final class OutboxCommandsTest extends TestCase
{
    public function testTheStateChangesAreRelayedToTheSinkOfTheApplication(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $outbox = $harness->privateService(Outbox::class, Outbox::class);
        $pending = \count($outbox->pending(100));

        $command = $harness->command('urssaf:dpae:relay-outbox');
        $status = $command->execute([]);

        static::assertGreaterThan(0, $pending);
        static::assertSame(0, $status);
        static::assertSame([['Dispatched', 'Failed', 'Deferred'], [(string) $pending, '0', '0']], Output::rows($command->getDisplay()));
        static::assertCount($pending, $harness->privateService('test.sink', RecordingIntegrationEventSink::class)->events());
        static::assertSame([], $outbox->pending(100));
    }

    public function testTheLimitBoundsTheRunAndMustBePositive(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        Scenario::deposit($harness, 'tenant-1', 'ref-2', 'b');
        $pending = \count($harness->privateService(Outbox::class, Outbox::class)->pending(100));
        static::assertSame(2, $pending);

        $limited = $harness->command('urssaf:dpae:relay-outbox');
        $limited->execute(['--limit' => '1']);
        $invalid = $harness->command('urssaf:dpae:relay-outbox');
        $zero = $harness->command('urssaf:dpae:relay-outbox');

        static::assertSame([['Dispatched', 'Failed', 'Deferred'], ['1', '0', '0']], Output::rows($limited->getDisplay()));
        static::assertSame(1, $invalid->execute(['--limit' => 'many']));
        static::assertStringContainsString('The --limit option must be a positive integer.', $invalid->getDisplay());
        static::assertSame(1, $zero->execute(['--limit' => '0']));
        static::assertCount($pending - 1, $harness->privateService(Outbox::class, Outbox::class)->pending(100));
    }

    public function testAnEventTheSinkRefusesFailsTheRunAndStaysPending(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.refusing_sink']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $pending = \count($harness->privateService(Outbox::class, Outbox::class)->pending(100));

        $command = $harness->command('urssaf:dpae:relay-outbox');

        static::assertSame(1, $command->execute([]));
        static::assertSame([['Dispatched', 'Failed', 'Deferred'], ['0', '1', (string) ($pending - 1)]], Output::rows($command->getDisplay()));
        static::assertCount($pending, $harness->privateService(Outbox::class, Outbox::class)->pending(100));
    }

    public function testAnOperationAlreadyRunningIsSkipped(): void
    {
        $harness = Harness::boot(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]), false, false, true);
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $held = HeldLocks::hold($harness, 'urssaf_dpae.relay-outbox.*');

        $command = $harness->command('urssaf:dpae:relay-outbox');

        static::assertSame(0, $command->execute([]));
        static::assertStringContainsString('Already running (lock "urssaf_dpae.relay-outbox.*" is held): skipped.', $command->getDisplay());
        static::assertNotSame([], $harness->privateService(Outbox::class, Outbox::class)->pending(10));
        $held->release();
    }

    public function testThePurgeDeletesTheEntriesDispatchedBeforeTheRetention(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink', 'retention' => 'P1D']]));
        Scenario::deposit($harness, 'tenant-1', 'ref-1', 'a');
        $pending = \count($harness->privateService(Outbox::class, Outbox::class)->pending(100));
        static::assertSame(0, $harness->command('urssaf:dpae:relay-outbox')->execute([]));

        $early = $harness->command('urssaf:dpae:purge-outbox');
        static::assertSame(0, $early->execute([]));
        $harness->clock->modify('+2 days');
        $late = $harness->command('urssaf:dpae:purge-outbox');
        static::assertSame(0, $late->execute([]));

        static::assertStringContainsString('0 dispatched outbox entries deleted.', $early->getDisplay());
        static::assertStringContainsString(\sprintf('%d dispatched outbox entries deleted.', $pending), $late->getDisplay());
    }

    public function testThePurgeSkipsARunInProgress(): void
    {
        $harness = Harness::boot(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]), false, false, true);
        $held = HeldLocks::hold($harness, 'urssaf_dpae.purge-outbox.*');

        $command = $harness->command('urssaf:dpae:purge-outbox');

        static::assertSame(0, $command->execute([]));
        static::assertStringContainsString('Already running (lock "urssaf_dpae.purge-outbox.*" is held): skipped.', $command->getDisplay());
        $held->release();
    }

    public function testTheSinksOfTheConfiguration(): void
    {
        $messenger = Harness::boot(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'messenger', 'bus' => 'messenger.default_bus']]), false, true, false);
        $dispatcher = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'event_dispatcher']]));
        $custom = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink']]));

        static::assertInstanceOf(MessengerIntegrationEventSink::class, $messenger->privateService(IntegrationEventSink::class, IntegrationEventSink::class));
        static::assertInstanceOf(EventDispatcherIntegrationEventSink::class, $dispatcher->privateService(IntegrationEventSink::class, IntegrationEventSink::class));
        static::assertSame($custom->privateService('test.sink', IntegrationEventSink::class), $custom->privateService(IntegrationEventSink::class, IntegrationEventSink::class));
    }

    public function testTheOutboxOfTheApplicationIsUsed(): void
    {
        $harness = Harness::bootDefault(Simulated::config(['outbox' => ['enabled' => true, 'sink' => 'test.sink', 'service' => 'test.outbox']]));

        static::assertSame($harness->privateService('test.outbox', Outbox::class), $harness->privateService(Outbox::class, Outbox::class));
    }

    public function testNothingIsRelayedWhenTheOutboxIsDisabled(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));

        $this->expectException(CommandNotFoundException::class);

        $harness->command('urssaf:dpae:relay-outbox');
    }
}
