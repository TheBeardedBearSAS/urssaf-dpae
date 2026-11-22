<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Port\Urssaf;

use RuntimeException;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;

/**
 * No request can be made for the return, so nothing was sent (ADR 0002): it has neither `url` nor `id` (a `KO` may come
 * without a document), or its `url` is not on the configured download origin and it has no `id` to rebuild the path
 * from. The origin of such a `url` is reported, never followed (the download carries the token). Not retryable.
 */
final class ReturnNotDownloadable extends RuntimeException implements UrssafException
{
    private function __construct(string $message, private readonly ReturnIdentity $identity, private readonly ?string $urlOrigin)
    {
        parent::__construct($message);
    }

    public static function withoutDocument(ReturnReference $return): self
    {
        return new self('The URSSAF return has neither a download url nor an id.', $return->identity(), null);
    }

    /**
     * @param string $urlOrigin scheme, host and port of the `url` that was refused (never its path nor query)
     */
    public static function urlOutsideDownloadOrigin(ReturnReference $return, string $urlOrigin): self
    {
        return new self(
            'The url of the URSSAF return is not on the configured download origin and the return has no id.',
            $return->identity(),
            $urlOrigin,
        );
    }

    public function identity(): ReturnIdentity
    {
        return $this->identity;
    }

    /**
     * Null when the return has no `url` at all.
     */
    public function urlOrigin(): ?string
    {
        return $this->urlOrigin;
    }
}
