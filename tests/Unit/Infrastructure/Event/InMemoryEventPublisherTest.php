<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Event;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Infrastructure\Event\InMemoryEventPublisher;
use TheBeardedBear\UrssafDpae\Infrastructure\Event\NullEventPublisher;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class InMemoryEventPublisherTest extends TestCase
{
    private static function event(string $reference): DepositReceived
    {
        return new DepositReceived(DepositId::fromString('d1'), Samples::tenant('t'), Samples::reference($reference), null, Samples::at(Samples::NOW));
    }

    public function testItKeepsThePublishedEventsInOrder(): void
    {
        $publisher = new InMemoryEventPublisher();
        $first = self::event('a');
        $second = self::event('b');
        $third = self::event('c');

        static::assertSame([], $publisher->published());

        $publisher->publish($first, $second);
        $publisher->publish($third);
        $publisher->publish();

        static::assertSame([$first, $second, $third], $publisher->published());
    }

    #[DoesNotPerformAssertions]
    public function testTheNullPublisherPublishesNowhere(): void
    {
        (new NullEventPublisher())->publish(self::event('a'));
    }
}
