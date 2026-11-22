<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

/**
 * Business result at one step of the processing (`statut`). Backed by the URSSAF codes.
 */
enum ReturnStatus: string
{
    case Ok = 'OK';
    case Failed = 'KO';
    case Anomaly = 'ANO';
    case InProgress = 'EN_COURS';

    /**
     * Null for codes unknown to this version of the package.
     */
    public static function fromCode(string $code): ?self
    {
        return self::tryFrom($code);
    }
}
