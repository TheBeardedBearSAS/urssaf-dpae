<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Contract;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Application\Port\ReturnContentStore;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;

/**
 * Behaviour shared by every {@see ReturnContentStore} adapter (ADR 0008).
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 */
trait ReturnContentStoreContract
{
    abstract protected function createContents(): ReturnContentStore;

    public function testAnUnknownContentIsNotFound(): void
    {
        static::assertNotInstanceOf(ReturnContent::class, $this->createContents()->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null)));
    }

    public function testASavedContentIsReadBackByteForByte(): void
    {
        $contents = $this->createContents();
        $bytes = "%PDF-1.4\0\x01\xFF\xFE binary \xE9";
        $contents->save(Cases::tenant('t1'), Cases::identity('flow-a', 10, null), ReturnContent::of($bytes, 'application/pdf'));

        $read = $contents->find(Cases::tenant('t1'), Cases::identity('flow-a', 10, null));

        static::assertInstanceOf(ReturnContent::class, $read);
        static::assertSame($bytes, $read->bytes());
        static::assertSame('application/pdf', $read->contentType());
    }

    public function testASavedContentIsReplacedUnderItsIdentity(): void
    {
        $contents = $this->createContents();
        $identity = Cases::identity('flow-a', 10, 'ops-1');
        $contents->save(Cases::tenant('t1'), $identity, ReturnContent::of('<old/>', 'application/xml'));
        $contents->save(Cases::tenant('t1'), $identity, ReturnContent::of('<new/>', 'application/xml; charset=UTF-8'));

        $read = $contents->find(Cases::tenant('t1'), $identity);

        static::assertInstanceOf(ReturnContent::class, $read);
        static::assertSame('<new/>', $read->bytes());
        static::assertSame('application/xml; charset=UTF-8', $read->contentType());
    }

    public function testAContentIsKeptPerTenantFluxNatureAndOps(): void
    {
        $contents = $this->createContents();
        $variants = [
            ['t1', 'flow-a', 10, null],
            ['t1', 'flow-a', 10, ''],
            ['t1', 'flow-a', 10, 'ops-1'],
            ['t1', 'flow-a', 11, null],
            ['t1', 'flow-b', 10, null],
            ['t2', 'flow-a', 10, null],
        ];
        foreach ($variants as $index => [$tenant, $flow, $nature, $ops]) {
            $contents->save(Cases::tenant($tenant), Cases::identity($flow, $nature, $ops), ReturnContent::of('<c' . $index . '/>', 'application/xml'));
        }

        foreach ($variants as $index => [$tenant, $flow, $nature, $ops]) {
            $read = $contents->find(Cases::tenant($tenant), Cases::identity($flow, $nature, $ops));
            static::assertInstanceOf(ReturnContent::class, $read);
            static::assertSame('<c' . $index . '/>', $read->bytes());
        }
        static::assertNotInstanceOf(ReturnContent::class, $contents->find(Cases::tenant('t3'), Cases::identity('flow-a', 10, null)));
    }
}
