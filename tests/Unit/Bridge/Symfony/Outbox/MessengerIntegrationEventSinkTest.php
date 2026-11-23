<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Outbox;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Outbox\MessengerIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\RecordingMessageBus;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class MessengerIntegrationEventSinkTest extends TestCase
{
    public function testTheEventIsDispatchedOnTheBus(): void
    {
        $bus = new RecordingMessageBus();
        $event = new DepositReceived('e1', Samples::NOW, 't', 'd1', 'ref', null);

        (new MessengerIntegrationEventSink($bus))->dispatch($event);

        static::assertSame([$event], $bus->messages());
    }
}
