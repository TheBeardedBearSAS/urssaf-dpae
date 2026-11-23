<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Shared;

use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;

/**
 * Forbids `serialize()` and `unserialize()` on an object holding raw URSSAF documents (personal data). Refusing
 * `__unserialize()` also stops a crafted string from building the object without its constructor.
 *
 * Limit: `var_export()`, an `(array)` cast and reflection bypass `__debugInfo()` and these hooks; no PHP mechanism
 * can forbid them, so callers must never export a payload object.
 *
 * @internal
 */
trait ForbidsSerialization
{
    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw PayloadNotSerializable::serializing(self::class);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw PayloadNotSerializable::unserializing(self::class);
    }
}
