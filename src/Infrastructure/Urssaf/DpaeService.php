<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf;

/**
 * The four services of the DPAE API, each with its own host (NAT/5492 "Table d'adressage", ADR 0002).
 */
enum DpaeService: string
{
    case Authentication = 'authentication';
    case Deposit = 'deposit';
    case Consultation = 'consultation';
    case Download = 'download';

    /**
     * Published host, without the concentrator port.
     */
    public function defaultBaseUri(): string
    {
        return match ($this) {
            self::Authentication => 'https://mon.urssaf.fr',
            self::Deposit => 'https://depot.dpae-edi.urssaf.fr',
            self::Consultation => 'https://consultation.dpae-edi.urssaf.fr',
            self::Download => 'https://telechargement.dpae-edi.urssaf.fr',
        };
    }
}
