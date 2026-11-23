<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port;

/**
 * The calls made to URSSAF, named in the exceptions of the gateway and in the logs.
 */
enum Operation: string
{
    /** `POST /authentifier_dpae`, the XML token call (ADR 0001). */
    case Authenticate = 'authenticate';

    /** `POST /deposer-dsn/1.0`, the only write (ADR 0005). */
    case Deposit = 'deposit';

    /** `GET /lister-retours-declarant/1.0/{dateDebut}[/{dateFin}]` (ADR 0006). */
    case ListReturns = 'list_returns';

    /** `GET /telecharger-retour/1.0/{idFlux}/{idRetour}`, or the `url` of the return (ADR 0002). */
    case DownloadReturn = 'download_return';
}
