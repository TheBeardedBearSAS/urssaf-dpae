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

/**
 * Ordered log shared by the doubles, to assert what happened before what.
 */
final class Journal
{
    /**
     * @var list<string>
     */
    private array $entries = [];

    public function note(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public function entries(): array
    {
        return $this->entries;
    }
}
