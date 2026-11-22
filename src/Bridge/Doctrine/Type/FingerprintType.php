<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\Conversion\StringValueConversion;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;

/**
 * Column type of {@see FlowFingerprint}: a VARCHAR holding its string value.
 */
final class FingerprintType extends Type
{
    public const NAME = 'urssaf_dpae_fingerprint';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return StringValueConversion::toDatabase($value, FlowFingerprint::class, self::class, static fn(FlowFingerprint $value): string => $value->value());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?FlowFingerprint
    {
        return StringValueConversion::toPhp($value, self::class, FlowFingerprint::fromString(...));
    }
}
