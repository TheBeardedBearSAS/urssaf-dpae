<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Exception;

use LogicException;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;

final class UnexpectedUrssafResponseTest extends TestCase
{
    public function testItIsAPackageException(): void
    {
        $exception = UnexpectedUrssafResponse::invalidPayload('a flux has no id');

        static::assertSame('Unexpected URSSAF response: a flux has no id.', $exception->getMessage());
        static::assertNull($exception->getPrevious());
    }

    public function testItKeepsTheCause(): void
    {
        $cause = new LogicException('cause');
        $exception = UnexpectedUrssafResponse::causedBy('the charset is not supported', $cause);

        static::assertSame('Unexpected URSSAF response: the charset is not supported.', $exception->getMessage());
        static::assertSame($cause, $exception->getPrevious());
        static::assertSame(0, $exception->getCode());
    }
}
