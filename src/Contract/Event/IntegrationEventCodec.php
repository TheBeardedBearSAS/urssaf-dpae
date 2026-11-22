<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Contract\Event;

use InvalidArgumentException;

/**
 * Text form of an integration event, for an outbox that stores it.
 */
final class IntegrationEventCodec
{
    /**
     * The classes {@see self::decode()} may rebuild.
     */
    public const CLASSES = [
        DepositReceived::class,
        DepositRejected::class,
        DepositOutcomeUnknown::class,
        DepositFailed::class,
        DepositParked::class,
        DepositAdopted::class,
        ReturnAvailable::class,
        ReturnDownloaded::class,
    ];

    public static function encode(IntegrationEvent $event): string
    {
        return serialize($event);
    }

    /**
     * @throws InvalidArgumentException when the data is not an encoded integration event
     */
    public static function decode(string $data): IntegrationEvent
    {
        // @mago-expect analysis:mixed-assignment unserialize() is untyped: the instanceof check below narrows it.
        $event = unserialize($data, ['allowed_classes' => self::CLASSES]);

        return $event instanceof IntegrationEvent ? $event : throw new InvalidArgumentException('The data is not an encoded integration event.');
    }
}
