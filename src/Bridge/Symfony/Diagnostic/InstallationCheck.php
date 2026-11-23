<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic;

/**
 * A check run by `urssaf:dpae:check`. Services tagged "urssaf_dpae.installation_check" (autoconfigured) are run after the
 * checks of the package, unless their tag has a positive priority.
 */
interface InstallationCheck
{
    public const TAG = 'urssaf_dpae.installation_check';

    /**
     * @return list<CheckResult>
     */
    public function run(CheckContext $context): array;
}
