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
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;

/**
 * Column type of {@see DepositId}: a VARCHAR holding its string value.
 */
final class DepositIdType extends Type
{
    public const NAME = 'urssaf_dpae_deposit_id';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getStringTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return StringValueConversion::toDatabase($value, DepositId::class, self::class, static fn(DepositId $value): string => $value->value());
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DepositId
    {
        return StringValueConversion::toPhp($value, self::class, DepositId::fromString(...));
    }
}
