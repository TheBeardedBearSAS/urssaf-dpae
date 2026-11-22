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
use TheBeardedBear\UrssafDpae\Application\Port\SyncCursorRepository;
use TheBeardedBear\UrssafDpae\Domain\Return\SyncCursor;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * Behaviour shared by every {@see SyncCursorRepository} adapter (ADR 0006, 0008).
 *
 * @phpstan-require-extends TestCase
 * @require-extends TestCase
 */
trait SyncCursorRepositoryContract
{
    abstract protected function createCursors(): SyncCursorRepository;

    public function testAnUnknownCursorIsNotFound(): void
    {
        static::assertNotInstanceOf(SyncCursor::class, $this->createCursors()->find(Cases::tenant('t1')));
    }

    public function testAnInitialCursorIsReadBack(): void
    {
        $cursors = $this->createCursors();
        $cursors->save(SyncCursor::initial(Cases::tenant('t1')));

        $read = $cursors->find(Cases::tenant('t1'));

        static::assertEquals(SyncCursor::initial(Cases::tenant('t1')), $read);
        static::assertNotInstanceOf(Instant::class, $read?->position());
        static::assertNotInstanceOf(Instant::class, $read?->lastSuccessAt());
    }

    public function testACursorIsReplacedUnderItsTenantAndKeptPerTenant(): void
    {
        $cursors = $this->createCursors();
        $cursors->save(SyncCursor::initial(Cases::tenant('t1')));
        $cursors->save(SyncCursor::initial(Cases::tenant('t2'))->refused()->refused());
        $advanced = SyncCursor::initial(Cases::tenant('t1'))->refused()->advancedTo(Samples::at(Cases::T1), Samples::at(Cases::T2))->refused();
        $cursors->save($advanced);

        $first = $cursors->find(Cases::tenant('t1'));
        $second = $cursors->find(Cases::tenant('t2'));

        static::assertInstanceOf(SyncCursor::class, $first);
        static::assertInstanceOf(SyncCursor::class, $second);
        static::assertEquals($advanced, $first);
        static::assertTrue($first->position()?->equals(Samples::at(Cases::T1)));
        static::assertTrue($first->lastSuccessAt()?->equals(Samples::at(Cases::T2)));
        static::assertSame(1, $first->consecutiveRefusals());
        static::assertSame(2, $second->consecutiveRefusals());
    }
}
