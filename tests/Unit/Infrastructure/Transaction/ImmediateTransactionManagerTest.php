<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Transaction;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Transaction\ImmediateTransactionManager;

final class ImmediateTransactionManagerTest extends TestCase
{
    public function testItRunsTheOperationAndReturnsItsResult(): void
    {
        $calls = 0;

        $result = (new ImmediateTransactionManager())->transactional(static function () use (&$calls): string {
            ++$calls;

            return 'result';
        });

        static::assertSame('result', $result);
        static::assertSame(1, $calls);
    }

    public function testAFailureIsNotSwallowed(): void
    {
        $this->expectException(LogicException::class);

        (new ImmediateTransactionManager())->transactional(static function (): never {
            throw new LogicException('boom');
        });
    }
}
