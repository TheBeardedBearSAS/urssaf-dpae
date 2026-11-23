<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\TokenStore;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Authentication\DpaeToken;
use TheBeardedBear\UrssafDpae\Infrastructure\TokenStore\InMemoryTokenStore;

final class InMemoryTokenStoreTest extends TestCase
{
    public function testItKeepsATokenPerKey(): void
    {
        $store = new InMemoryTokenStore();
        $first = DpaeToken::fromResponseBody('Zmlyc3Q=');
        $second = DpaeToken::fromResponseBody('c2Vjb25k');

        static::assertNull($store->get('a'));

        $store->save('a', $first);
        $store->save('b', $second);

        static::assertSame($first, $store->get('a'));
        static::assertSame($second, $store->get('b'));
    }

    public function testSavingAgainReplacesAndDeletingForgets(): void
    {
        $store = new InMemoryTokenStore();
        $store->save('a', DpaeToken::fromResponseBody('Zmlyc3Q='));
        $replacement = DpaeToken::fromResponseBody('c2Vjb25k');
        $store->save('a', $replacement);

        static::assertSame($replacement, $store->get('a'));

        $store->save('b', DpaeToken::fromResponseBody('b3RoZXI='));
        $store->delete('a');

        static::assertNull($store->get('a'));
        static::assertNotNull($store->get('b'));
        $store->delete('missing');
    }
}
