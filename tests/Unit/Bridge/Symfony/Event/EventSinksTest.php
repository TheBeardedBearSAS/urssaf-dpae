<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Event;

use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Event\EventDispatcherIntegrationEventSink;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Event\SymfonyEventPublisher;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Contract\Event\IntegrationEvent;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived as DepositReceivedDomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\ThrowingListener;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class EventSinksTest extends TestCase
{
    public function testTheDomainEventsAreDispatchedOneByOneInOrder(): void
    {
        $dispatcher = new EventDispatcher();
        $seen = [];
        $dispatcher->addListener(DepositReceivedDomainEvent::class, static function (DepositReceivedDomainEvent $event) use (&$seen): void {
            $seen[] = $event->depositId->value();
        });
        $first = self::domainEvent('d1');
        $second = self::domainEvent('d2');

        (new SymfonyEventPublisher($dispatcher))->publish($first, $second);
        (new SymfonyEventPublisher($dispatcher))->publish();

        static::assertSame(['d1', 'd2'], $seen);
    }

    public function testAListenerThatThrowsInterruptsThePublication(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DepositReceivedDomainEvent::class, new ThrowingListener());
        $event = self::domainEvent('d1');

        $this->expectException(LogicException::class);

        (new SymfonyEventPublisher($dispatcher))->publish($event);
    }

    public function testTheIntegrationEventsReachTheListeners(): void
    {
        $dispatcher = new EventDispatcher();
        $received = [];
        $dispatcher->addListener(DepositReceived::class, static function (IntegrationEvent $event) use (&$received): void {
            $received[] = $event;
        });
        $event = new DepositReceived('e1', Samples::NOW, 't', 'd1', 'ref', null);

        (new EventDispatcherIntegrationEventSink($dispatcher))->dispatch($event);

        static::assertSame([$event], $received);
    }

    public function testAListenerThatThrowsKeepsTheEventInTheOutbox(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DepositReceived::class, new ThrowingListener());

        $this->expectException(LogicException::class);

        (new EventDispatcherIntegrationEventSink($dispatcher))->dispatch(new DepositReceived('e1', Samples::NOW, 't', 'd1', 'ref', null));
    }

    private static function domainEvent(string $depositId): DepositReceivedDomainEvent
    {
        return new DepositReceivedDomainEvent(DepositId::fromString($depositId), TenantId::fromString('t'), ExternalReference::fromString('ref'), null, Samples::at(Samples::NOW));
    }
}
