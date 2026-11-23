<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Logging;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived;
use TheBeardedBear\UrssafDpae\Infrastructure\Logging\PsrLoggerFailureReporter;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;
use TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http\RecordingLogger;

final class PsrLoggerFailureReporterTest extends TestCase
{
    public function testItLogsTheClassAndTheCountButNeverTheMessage(): void
    {
        $logger = new RecordingLogger();
        $event = new DepositReceived(DepositId::fromString('d1'), Samples::tenant('t'), Samples::reference('r'), null, Samples::at(Samples::NOW));

        (new PsrLoggerFailureReporter($logger))->eventPublicationFailed(new LogicException('secret NIR 1234'), [$event, $event]);

        static::assertCount(1, $logger->records());
        static::assertSame('error', $logger->records()[0]['level']);
        static::assertSame('A domain event listener failed after the commit.', $logger->records()[0]['message']);
        static::assertSame(['exception' => LogicException::class, 'events' => 2], $logger->records()[0]['context']);
        static::assertStringNotContainsString('secret', $logger->dump());
    }
}
