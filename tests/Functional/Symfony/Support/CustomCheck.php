<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use Override;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckContext;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckResult;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\CheckStatus;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Diagnostic\InstallationCheck;

/**
 * A check of the host application, picked up by autoconfiguration.
 */
final class CustomCheck implements InstallationCheck
{
    #[Override]
    public function run(CheckContext $context): array
    {
        return [new CheckResult('custom', '', CheckStatus::Ok, 'Run by the host.')];
    }
}
