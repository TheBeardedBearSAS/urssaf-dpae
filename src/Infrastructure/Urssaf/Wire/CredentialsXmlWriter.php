<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire;

use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Writes the body of the authentication call. An interface because the shape is not published (ADR 0001): a different
 * shape found by the contract tests is a one-class change.
 */
interface CredentialsXmlWriter
{
    /**
     * @throws InvalidValue when a value cannot be written as XML
     */
    public function write(DeclarantCredentials $credentials): string;
}
