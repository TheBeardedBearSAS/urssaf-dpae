<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Service\IntegrationEventFacts;
use TheBeardedBear\UrssafDpae\Application\Service\IntegrationEventTranslator;
use TheBeardedBear\UrssafDpae\Contract\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

final class IntegrationEventTranslatorFactsTest extends TestCase
{
    use IntegrationEventTranslatorFixtures;

    public function testAnUnknownDomainEventGivesNothingAndOrderIsKept(): void
    {
        $unknown = new class implements DomainEvent {
            public function occurredAt(): Instant
            {
                return Samples::at('2022-02-01T12:00:00Z');
            }
        };

        $events = IntegrationEventTranslator::translate(self::received(Samples::flowId('F1')), $unknown, self::received(Samples::flowId('F2')));

        static::assertCount(2, $events);
        static::assertInstanceOf(DepositReceived::class, $events[0]);
        static::assertSame('F1', $events[0]->flowId);
        static::assertInstanceOf(DepositReceived::class, $events[1]);
        static::assertSame('F2', $events[1]->flowId);
        static::assertSame([], IntegrationEventTranslator::translate());
    }

    public function testTheFactsIdHasTheShapeOfAUuidAndDependsOnEveryInput(): void
    {
        $at = Samples::at(self::AT);
        $base = IntegrationEventFacts::id('type', 'aggregate', $at, ['a']);

        static::assertMatchesRegularExpression(self::ID_FORMAT, $base);
        static::assertSame($base, IntegrationEventFacts::id('type', 'aggregate', $at, ['a']));
        static::assertNotSame($base, IntegrationEventFacts::id('other', 'aggregate', $at, ['a']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'other', $at, ['a']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'aggregate', Samples::at('2022-02-01T12:00:01Z'), ['a']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'aggregate', $at, ['b']));
        static::assertNotSame($base, IntegrationEventFacts::id('type', 'aggregate', $at, ['a', null]));
        static::assertSame(hash('sha256', serialize(['type', 'aggregate', self::AT, ['a']])), str_replace('-', '', $base) . substr(hash('sha256', serialize(['type', 'aggregate', self::AT, ['a']])), 32));
    }
}
