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
 * One line of the `urssaf:dpae:check` report.
 */
final readonly class CheckResult
{
    /**
     * @param string $check   what is checked ("credentials", "database schema"...)
     * @param string $subject the tenant or resource checked, empty when global
     * @param string $detail  what was found, and what to do when it is not ok (never a secret)
     */
    public function __construct(
        public string $check,
        public string $subject,
        public CheckStatus $status,
        public string $detail,
    ) {}
}
