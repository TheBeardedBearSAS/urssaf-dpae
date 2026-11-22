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
 * Whether the document of a tracked return was fetched (ADR 0006, 0008). `Purged`: URSSAF answered 404 (returns are
 * kept three months); `NotDownloadable`: the return has no document, or its `url` is outside the download origin.
 */
enum DownloadState: string
{
    case Pending = 'pending';
    case Downloaded = 'downloaded';
    case NotDownloadable = 'not_downloadable';
    case Purged = 'purged';
}
