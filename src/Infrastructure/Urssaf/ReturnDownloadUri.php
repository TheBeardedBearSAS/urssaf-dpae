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

use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNotDownloadable;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnId;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;

/**
 * Where a return is downloaded from (ADR 0002): the `url` of the return when it is on the configured download origin,
 * else the path rebuilt from `flux.id` and `retour.id`. The download sends the token, so no other host is ever contacted.
 *
 * @internal
 */
final class ReturnDownloadUri
{
    /**
     * @throws ReturnNotDownloadable the return has neither a usable `url` nor an `id`
     * @throws InvalidValue          `return_id.too_long` for a stored `id` over 10 characters
     */
    public static function of(ServiceEndpoints $endpoints, ReturnReference $return, ServiceCode $serviceCode): string
    {
        $url = $return->url();
        if ($url !== null && $endpoints->isOnDownloadOrigin($url, $serviceCode)) {
            return $url;
        }

        $id = $return->downloadId();
        if (!$id instanceof ReturnId) {
            throw $url === null
                ? ReturnNotDownloadable::withoutDocument($return)
                : ReturnNotDownloadable::urlOutsideDownloadOrigin($return, WebUri::originOf($url) ?? 'unparsable');
        }

        return $endpoints->uri(
            DpaeService::Download,
            $serviceCode,
            UrssafEndpoints::RETURN_DOWNLOAD_PATH . '/' . rawurlencode($return->flowId()->value()) . '/' . rawurlencode($id->value()),
        );
    }
}
