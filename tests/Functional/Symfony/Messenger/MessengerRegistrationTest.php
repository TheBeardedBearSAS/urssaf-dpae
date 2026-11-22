<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Messenger;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\DepositDpaeMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\ReconcileDepositsMessageHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessage;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\SynchronizeReturnsMessageHandler;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Flows;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Harness;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Simulated;

final class MessengerRegistrationTest extends TestCase
{
    public function testNothingIsRegisteredWhenMessengerIsDisabled(): void
    {
        $harness = Harness::bootDefault(Simulated::config([]));

        static::assertFalse($harness->hasPrivate(DepositDpaeMessageHandler::class));
        static::assertFalse($harness->hasPrivate(SynchronizeReturnsMessageHandler::class));
        static::assertFalse($harness->hasPrivate(ReconcileDepositsMessageHandler::class));
    }

    public function testTheHandlersAreRegistered(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, false);

        foreach ([DepositDpaeMessageHandler::class, SynchronizeReturnsMessageHandler::class, ReconcileDepositsMessageHandler::class] as $handler) {
            static::assertTrue($harness->hasPrivate($handler), $handler);
        }
    }

    public function testTheMessagesAreScalarAndRouted(): void
    {
        $harness = Harness::boot(Simulated::config(['messenger' => ['enabled' => true]]), false, true, false);
        $bus = $harness->privateService('messenger.default_bus', MessageBusInterface::class);
        $flow = Flows::xml(DeclarationMode::Test, 'a');

        $bus->dispatch(DepositDpaeMessage::of('tenant-1', 'ref-1', DeclarationMode::Test, $flow));
        $bus->dispatch(new SynchronizeReturnsMessage('tenant-1'));
        $bus->dispatch(new ReconcileDepositsMessage('tenant-1'));

        $sent = $harness->privateService('messenger.transport.async', InMemoryTransport::class)->getSent();
        static::assertCount(3, $sent);
        $message = $sent[0]->getMessage();
        static::assertInstanceOf(DepositDpaeMessage::class, $message);
        static::assertSame(['tenant-1', 'ref-1', 'test', base64_encode($flow)], [$message->tenantId, $message->externalReference, $message->mode, $message->flow]);
        static::assertSame($flow, base64_decode($message->flow, true));
    }
}
