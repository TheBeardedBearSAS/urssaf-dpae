<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Exception;

use LogicException;

/**
 * Thrown when an object that holds a raw URSSAF document (a flow, an answer, a returned document: they contain
 * personal data) is serialized or unserialized. Persistence goes through the dedicated stores, never through
 * `serialize()`.
 */
final class PayloadNotSerializable extends LogicException implements UrssafException
{
    public static function serializing(string $class): self
    {
        return new self(\sprintf('%s must never be serialized.', $class));
    }

    public static function unserializing(string $class): self
    {
        return new self(\sprintf('%s must never be unserialized.', $class));
    }
}
