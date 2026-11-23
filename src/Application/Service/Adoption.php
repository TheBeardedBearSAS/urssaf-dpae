<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

/**
 * What the reconciliation did with a deposit and the flux found for it (ADR 0005).
 */
enum Adoption
{
    /**
     * The deposit took the flux (it is marked as adopted by reconciliation).
     */
    case Adopted;

    /**
     * Nothing to adopt yet: no sole candidate, a window still open, an unusable status, or the deposit moved meanwhile.
     */
    case Undecided;

    /**
     * A sole candidate was found, but the policy does not let the package adopt it alone: a human decides.
     */
    case NeedsReview;
}
