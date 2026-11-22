<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Credentials;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Credentials\LazyCredentialsProvider;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

final class LazyCredentialsProviderTest extends TestCase
{
    public function testTheCredentialsOfATenantAreBuiltWhenItIsUsedAndOnlyThen(): void
    {
        $credentials = self::credentials();
        $built = new ArrayObject();
        $provider = new LazyCredentialsProvider([
            'acme' => static function () use ($built, $credentials): DeclarantCredentials {
                $built->append('acme');

                return $credentials;
            },
        ]);

        static::assertCount(0, $built);
        static::assertSame($credentials, $provider->forTenant(TenantId::fromString('acme')));
        static::assertSame(['acme'], $built->getArrayCopy());
    }

    public function testItRefusesAnUnknownTenant(): void
    {
        $provider = new LazyCredentialsProvider([]);

        try {
            $provider->forTenant(TenantId::fromString('acme'));
            static::fail('RecordNotFound expected.');
        } catch (RecordNotFound $e) {
            static::assertSame('credentials.not_found', $e->violationCode());
            static::assertSame('acme', $e->identifier());
        }
    }

    private static function credentials(): DeclarantCredentials
    {
        return DeclarantCredentials::of(Siret::fromString('99999999999999'), 'John', 'Doe', 'Passw0rd-123', ServiceCode::direct());
    }
}
