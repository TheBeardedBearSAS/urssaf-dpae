<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection;

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Container factory of the credentials of a tenant: built when the service is first used, so that every value can be an
 * `%env()%` placeholder. The service code is any integer (an unknown one is preserved, ADR 0001), usually read with
 * `%env(int:...)%`.
 */
final class DeclarantCredentialsFactory
{
    /**
     * @throws InvalidValue when a value is not valid (a SIRET of 14 characters, lengths of the names and the password)
     */
    public static function create(
        string $siret,
        #[SensitiveParameter]
        string $nom,
        #[SensitiveParameter]
        string $prenom,
        #[SensitiveParameter]
        string $password,
        int $service,
    ): DeclarantCredentials {
        return DeclarantCredentials::of(Siret::fromString($siret), $nom, $prenom, $password, ServiceCode::fromInt($service));
    }

}
