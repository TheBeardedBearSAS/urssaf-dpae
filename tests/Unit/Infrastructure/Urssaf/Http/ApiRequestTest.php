<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;

final class ApiRequestTest extends TestCase
{
    public function testAReadHasNoBody(): void
    {
        $request = ApiRequest::get(Operation::ListReturns, 'https://example.test/a');

        static::assertSame(Operation::ListReturns, $request->operation());
        static::assertSame('GET', $request->method());
        static::assertSame('https://example.test/a', $request->uri());
        static::assertNull($request->body());
        static::assertSame('', $request->contentType());
    }

    public function testADepositCarriesTheFlowAndItsContentType(): void
    {
        $request = ApiRequest::postGzip(Operation::Deposit, 'https://example.test/d', '<flow/>', 'text/plain');

        static::assertSame(Operation::Deposit, $request->operation());
        static::assertSame('POST', $request->method());
        static::assertSame('https://example.test/d', $request->uri());
        static::assertSame('<flow/>', $request->body());
        static::assertSame('text/plain', $request->contentType());
    }
}
