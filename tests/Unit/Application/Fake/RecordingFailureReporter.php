<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Application\Port\FailureReporter;
use TheBeardedBear\UrssafDpae\Domain\Shared\DomainEvent;
use Throwable;

final class RecordingFailureReporter implements FailureReporter
{
    /**
     * @var list<array{Throwable, list<DomainEvent>}>
     */
    private array $reports = [];

    /**
     * @return list<array{Throwable, list<DomainEvent>}>
     */
    public function reports(): array
    {
        return $this->reports;
    }

    public function eventPublicationFailed(Throwable $failure, array $events): void
    {
        $this->reports[] = [$failure, $events];
    }
}
