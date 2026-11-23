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
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Serializer\DepositResultSerializer;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\Conversion\JsonConversion;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;

/**
 * Column type of the {@see DepositResult} of a deposit: a JSON document written by {@see DepositResultSerializer}.
 */
final class DepositResultType extends Type
{
    public const NAME = 'urssaf_dpae_deposit_result';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!$value instanceof DepositResult) {
            throw InvalidType::new($value, self::class, [DepositResult::class, 'null']);
        }

        return JsonConversion::encode(DepositResultSerializer::toArray($value));
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?DepositResult
    {
        return JsonConversion::decode($value, self::class, DepositResult::class, DepositResultSerializer::fromArray(...));
    }
}
