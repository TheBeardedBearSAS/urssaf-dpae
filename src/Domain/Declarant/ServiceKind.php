<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declarant;

/**
 * Net-Entreprises service codes documented by the specification (api-reference.md §2.1).
 */
enum ServiceKind: int
{
    /** "25 pour un jeton direct pour les cotisants". */
    case Direct = 25;

    /** "98 pour obtenir un jeton en tant que concentrateur". */
    case Concentrator = 98;

    /**
     * @return self|null null when the code is not one of the two documented values (e.g. the example `97`)
     */
    public static function fromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }
}
