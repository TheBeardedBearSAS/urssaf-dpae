<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\DependencyInjection\ConfigurationRules;

final class ConfigurationRulesTest extends TestCase
{
    public function testLockRequiredButMissing(): void
    {
        static::assertTrue(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => true]], false));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => true]], true));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => 'auto']], false));
        static::assertFalse(ConfigurationRules::lockRequiredButMissing(['lock' => ['enabled' => false]], false));
        static::assertFalse(ConfigurationRules::lockEnabledWithoutComponent(['lock' => ['enabled' => true]]));
    }

    public function testSchedulerRequiredButMissing(): void
    {
        static::assertTrue(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => true]], false));
        static::assertFalse(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => true]], true));
        static::assertFalse(ConfigurationRules::schedulerRequiredButMissing(['scheduler' => ['enabled' => false]], false));
        static::assertFalse(ConfigurationRules::schedulerEnabledWithoutComponent(['scheduler' => ['enabled' => true]]));
    }

    public function testTheCredentialSources(): void
    {
        $credentials = ['t' => ['siret' => 's']];

        static::assertTrue(ConfigurationRules::hasBothCredentialSources(['credentials' => $credentials, 'credentials_provider' => 'p']));
        static::assertFalse(ConfigurationRules::hasBothCredentialSources(['credentials' => $credentials, 'credentials_provider' => null]));
        static::assertFalse(ConfigurationRules::hasBothCredentialSources(['credentials' => [], 'credentials_provider' => 'p']));
        static::assertTrue(ConfigurationRules::hasNoCredentialSource(['credentials' => [], 'credentials_provider' => null]));
        static::assertFalse(ConfigurationRules::hasNoCredentialSource(['credentials' => $credentials, 'credentials_provider' => null]));
        static::assertFalse(ConfigurationRules::hasNoCredentialSource(['credentials' => [], 'credentials_provider' => 'p']));
    }

    public function testTheSimulatorIsRefusedWithTheRealMode(): void
    {
        static::assertTrue(ConfigurationRules::simulatedGatewayInRealMode(['declaration_mode' => 'real', 'gateway' => ['simulated' => true]]));
        static::assertFalse(ConfigurationRules::simulatedGatewayInRealMode(['declaration_mode' => 'test', 'gateway' => ['simulated' => true]]));
        static::assertFalse(ConfigurationRules::simulatedGatewayInRealMode(['declaration_mode' => 'real', 'gateway' => ['simulated' => false]]));
        static::assertFalse(ConfigurationRules::simulatedGatewayInRealMode(['declaration_mode' => '', 'gateway' => ['simulated' => true]]));
    }

    public function testTheOutboxNeedsAStorageWithTheCustomDriver(): void
    {
        static::assertTrue(ConfigurationRules::outboxWithoutStorage(['outbox' => ['enabled' => true, 'service' => null], 'persistence' => ['driver' => 'custom']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => ['enabled' => false, 'service' => null], 'persistence' => ['driver' => 'custom']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => ['enabled' => true, 'service' => 's'], 'persistence' => ['driver' => 'custom']]));
        static::assertFalse(ConfigurationRules::outboxWithoutStorage(['outbox' => ['enabled' => true, 'service' => null], 'persistence' => ['driver' => 'in_memory']]));
    }

    public function testTheCustomDriverNeedsEveryService(): void
    {
        $complete = ['driver' => 'custom', 'deposit_repository' => 'a', 'return_repository' => 'b', 'sync_cursor_repository' => 'c', 'return_content_store' => 'd', 'transaction_manager' => 'e'];

        static::assertFalse(ConfigurationRules::customDriverWithoutServices($complete));
        static::assertFalse(ConfigurationRules::customDriverWithoutServices([...$complete, 'driver' => 'in_memory', 'deposit_repository' => null]));
        foreach (['deposit_repository', 'return_repository', 'sync_cursor_repository', 'return_content_store', 'transaction_manager'] as $missing) {
            static::assertTrue(ConfigurationRules::customDriverWithoutServices([...$complete, $missing => null]), $missing);
        }
    }

    public function testTheFlowsCanOnlyBeKeptWithDoctrine(): void
    {
        static::assertTrue(ConfigurationRules::keepFlowsWithoutDoctrine(['driver' => 'in_memory', 'keep_flows' => true]));
        static::assertTrue(ConfigurationRules::keepFlowsWithoutDoctrine(['driver' => 'custom', 'keep_flows' => true]));
        static::assertFalse(ConfigurationRules::keepFlowsWithoutDoctrine(['driver' => 'doctrine', 'keep_flows' => true]));
        static::assertFalse(ConfigurationRules::keepFlowsWithoutDoctrine(['driver' => 'in_memory', 'keep_flows' => false]));
    }
}
