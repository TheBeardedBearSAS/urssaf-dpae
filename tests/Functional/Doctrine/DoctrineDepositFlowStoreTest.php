<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositFlowStore;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\TestDatabase;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;

final class DoctrineDepositFlowStoreTest extends TestCase
{
    public function testAnUnknownFlowIsNotFound(): void
    {
        static::assertNull((new DoctrineDepositFlowStore(TestDatabase::entityManager()))->find(Cases::depositId('dep-a')));
    }

    public function testAKeptFlowIsReadBackAndStoredCompressed(): void
    {
        $entityManager = TestDatabase::entityManager();
        $store = new DoctrineDepositFlowStore($entityManager);
        $flow = "<FR_DUE_Upload>\xE9</FR_DUE_Upload>";
        $store->keep(Cases::depositId('dep-a'), $flow);

        static::assertSame($flow, $store->find(Cases::depositId('dep-a')));
        $stored = $entityManager->getConnection()->fetchOne('SELECT flow FROM urssaf_dpae_deposit_flows');
        static::assertIsString($stored);
        static::assertSame($flow, gzdecode((string) base64_decode($stored, true)));
        static::assertNotSame($flow, $stored);
    }

    public function testAKeptFlowIsReplacedPerDeposit(): void
    {
        $store = new DoctrineDepositFlowStore(TestDatabase::entityManager());
        $store->keep(Cases::depositId('dep-a'), '<old/>');
        $store->keep(Cases::depositId('dep-b'), '<other/>');
        $store->keep(Cases::depositId('dep-a'), '<new/>');

        static::assertSame('<new/>', $store->find(Cases::depositId('dep-a')));
        static::assertSame('<other/>', $store->find(Cases::depositId('dep-b')));
    }

    public function testAKeptFlowIsErasedAloneAndTheErasureSaysWhetherThereWasOne(): void
    {
        $store = new DoctrineDepositFlowStore(TestDatabase::entityManager());
        $store->keep(Cases::depositId('dep-a'), '<a/>');
        $store->keep(Cases::depositId('dep-b'), '<b/>');

        static::assertTrue($store->delete(Cases::depositId('dep-a')));
        static::assertFalse($store->delete(Cases::depositId('dep-a')));

        static::assertNull($store->find(Cases::depositId('dep-a')));
        static::assertSame('<b/>', $store->find(Cases::depositId('dep-b')));
    }

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $entityManager = TestDatabase::entityManager();
        $entityManager->getConnection()->executeStatement("INSERT INTO urssaf_dpae_deposit_flows (deposit_id, flow) VALUES ('dep-a', '" . base64_encode('not gzip') . "')");
        $store = new DoctrineDepositFlowStore($entityManager);
        set_error_handler(static fn(): bool => true);

        try {
            $failure = Outcome::of(static fn(): ?string => $store->find(Cases::depositId('dep-a')));
        } finally {
            restore_error_handler();
        }

        static::assertInstanceOf(RuntimeException::class, $failure);
    }
}
