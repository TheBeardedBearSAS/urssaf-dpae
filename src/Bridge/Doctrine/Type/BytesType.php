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
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Type;

/**
 * Column type of raw bytes (a downloaded return, a compressed flow): stored in base 64 in a text column, so that every
 * platform returns the same string whatever it does with a BLOB (a stream on PostgreSQL, a string elsewhere) and the bytes
 * of an ISO-8859-1 document never meet the character set of the connection. The column is a third bigger than the bytes.
 */
final class BytesType extends Type
{
    public const NAME = 'urssaf_dpae_bytes';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['string', 'null']);
        }

        return base64_encode($value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['string', 'null']);
        }
        $bytes = base64_decode($value, true);

        return $bytes === false ? throw InvalidFormat::new($value, self::class, 'base 64') : $bytes;
    }
}
