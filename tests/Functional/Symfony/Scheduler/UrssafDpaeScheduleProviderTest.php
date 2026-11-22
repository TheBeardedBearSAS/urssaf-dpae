<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Scheduler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Schedule;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Scheduler\UrssafDpaeScheduleProvider;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\App\TestKernel;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class UrssafDpaeScheduleProviderTest extends TestCase
{
    public function testEveryCronOfThePackageIsScheduledAtItsFrequency(): void
    {
        $schedule = (new UrssafDpaeScheduleProvider(['sync_returns' => 'PT15M', 'reconcile' => 'PT30M', 'relay_outbox' => 'PT1M', 'purge_outbox' => 'P1D']))->getSchedule();

        static::assertSame([
            'urssaf:dpae:sync-returns every 900 seconds (PT15M)',
            'urssaf:dpae:reconcile every 1800 seconds (PT30M)',
            'urssaf:dpae:relay-outbox every 60 seconds (PT1M)',
            'urssaf:dpae:purge-outbox every 86400 seconds (P1D)',
        ], self::describe($schedule));
    }

    public function testATaskWithoutFrequencyIsLeftOut(): void
    {
        $schedule = (new UrssafDpaeScheduleProvider(['sync_returns' => null, 'reconcile' => 'PT1H', 'relay_outbox' => null]))->getSchedule();

        static::assertSame(['urssaf:dpae:reconcile every 3600 seconds (PT1H)'], self::describe($schedule));
        static::assertSame([], self::describe((new UrssafDpaeScheduleProvider([]))->getSchedule()));
    }

    public function testTheProviderIsRegisteredUnderTheScheduleNameOfThePackage(): void
    {
        $enabled = (new TestKernel(Simulated::config(['scheduler' => ['enabled' => true, 'frequencies' => ['reconcile' => 'PT30M', 'sync_returns' => null]]]), false, false, false))->definitions();
        $withOutbox = (new TestKernel(Simulated::config(['scheduler' => ['enabled' => true], 'outbox' => ['enabled' => true, 'sink' => 'test.sink']]), false, false, false))->definitions();
        $disabled = (new TestKernel([], false, false, false))->definitions();

        $definition = $enabled->getDefinition(UrssafDpaeScheduleProvider::class);
        static::assertSame(['scheduler.schedule_provider' => [['name' => 'urssaf_dpae']]], $definition->getTags());
        static::assertEquals([['sync_returns' => null, 'reconcile' => 'PT30M', 'relay_outbox' => null, 'purge_outbox' => null]], $definition->getArguments());
        static::assertEquals([['sync_returns' => 'PT15M', 'reconcile' => 'PT15M', 'relay_outbox' => 'PT1M', 'purge_outbox' => 'P1D']], $withOutbox->getDefinition(UrssafDpaeScheduleProvider::class)->getArguments());
        static::assertFalse($disabled->hasDefinition(UrssafDpaeScheduleProvider::class));
    }

    public function testTheScheduledMessagesRunTheCommands(): void
    {
        $harness = Harness::boot(Simulated::config(['scheduler' => ['enabled' => true], 'messenger' => ['enabled' => true]]), false, true, false);

        $envelope = $harness->privateService('messenger.default_bus', MessageBusInterface::class)->dispatch(new RunCommandMessage('urssaf:dpae:sync-returns --dry-run'));

        // @mago-expect analysis:mixed-assignment The handler result is untyped: the assertion below narrows it.
        $context = $envelope->last(HandledStamp::class)?->getResult();
        static::assertInstanceOf(RunCommandContext::class, $context);
        static::assertSame(0, $context->exitCode);
        static::assertStringContainsString('Dry run: 1 tenant(s) would be processed', $context->output);
    }

    /**
     * @return list<string> "<command> <trigger>" of every recurring message
     */
    private static function describe(Schedule $schedule): array
    {
        $described = [];
        foreach ($schedule->getRecurringMessages() as $recurring) {
            $context = new MessageContext('urssaf_dpae', $recurring->getId(), $recurring->getTrigger(), new DateTimeImmutable());
            $messages = iterator_to_array($recurring->getProvider()->getMessages($context), false);
            static::assertCount(1, $messages);
            $message = reset($messages);
            static::assertInstanceOf(RunCommandMessage::class, $message);
            $described[] = $message->input . ' ' . $recurring->getTrigger()->__toString();
        }

        return $described;
    }
}
