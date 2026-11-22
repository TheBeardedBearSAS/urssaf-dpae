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
 * Format of a downloaded return, given by the response `Content-Type`.
 */
enum ReturnContentType: string
{
    case Xml = 'application/xml';
    case Pdf = 'application/pdf';

    /**
     * Reads a `Content-Type` header value ("application/xml;charset=ISO-8859-1"); null for any other media type.
     */
    public static function fromHeader(string $header): ?self
    {
        return self::tryFrom(strtolower(trim(explode(';', $header)[0])));
    }
}
