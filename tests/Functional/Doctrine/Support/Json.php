<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support;

use JsonException;
use UnexpectedValueException;

/**
 * Reads the JSON documents the bridge stores, for the assertions of the tests.
 */
final class Json
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     */
    public static function decode(string $json): array
    {
        // @mago-expect analysis:mixed-assignment json_decode() is untyped: the check below narrows it.
        $document = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return \is_array($document) ? $document : throw new UnexpectedValueException('A JSON object or array was expected.');
    }
}
