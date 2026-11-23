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
 * Thrown when a credential (the token, the declarant credentials) is serialized or unserialized: it must never reach a
 * cache, a session, a queue or a database (ADR 0001).
 */
final class CredentialsNotSerializable extends LogicException implements UrssafException
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
