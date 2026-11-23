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
 * Nature of a return (`nature`, NAT/5492). Backed by the URSSAF codes.
 */
enum ReturnNature: int
{
    /** AEE or rejection notice (test and real). */
    case ReceiptOrRejection = 10;

    /** Conformity certificate or anomaly report (test and real). */
    case ConformityOrAnomalies = 11;

    /** Employee identification report, BIS (real only). */
    case IdentificationReport = 20;

    /** Conformity certificate of the DPAE (real only). */
    case DpaeConformity = 41;

    /**
     * Null for codes unknown to this version of the package.
     */
    public static function fromCode(int $code): ?self
    {
        return self::tryFrom($code);
    }
}
