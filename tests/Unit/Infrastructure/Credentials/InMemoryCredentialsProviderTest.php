<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Credentials;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\RecordNotFound;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;

final class InMemoryCredentialsProviderTest extends TestCase
{
    public function testItReturnsTheCredentialsOfTheTenant(): void
    {
        $credentials = DeclarantCredentials::of(Siret::fromString('99999999999999'), 'John', 'Doe', 'Passw0rd-123', ServiceCode::direct());
        $provider = new InMemoryCredentialsProvider(['acme' => $credentials]);

        static::assertSame($credentials, $provider->forTenant(TenantId::fromString('acme')));
    }

    public function testItRefusesAnUnknownTenant(): void
    {
        $provider = new InMemoryCredentialsProvider([]);

        try {
            $provider->forTenant(TenantId::fromString('acme'));
            static::fail('RecordNotFound expected.');
        } catch (RecordNotFound $e) {
            static::assertSame('credentials.not_found', $e->violationCode());
            static::assertSame('acme', $e->identifier());
        }
    }
}
