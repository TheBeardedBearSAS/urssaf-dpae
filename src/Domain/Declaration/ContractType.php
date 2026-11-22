<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declaration;

/**
 * Contract nature of the model, backed by the norm-control code of NAT/5491 (api-reference.md §3.4, code `44`):
 * `1` CDD, `2` CDI, `3` CTT. The XML wire value is a separate, configurable vocabulary ({@see DeclarationVocabulary}).
 */
enum ContractType: int
{
    case Cdd = 1;
    case Cdi = 2;
    case Ctt = 3;
}
