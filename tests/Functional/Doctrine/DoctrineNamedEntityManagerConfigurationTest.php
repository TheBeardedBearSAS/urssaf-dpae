<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\DoctrineIntegration;

final class DoctrineNamedEntityManagerConfigurationTest extends TestCase
{
    public function testItMapsTheNamedEntityManagerWhenTheHostHasADedicatedOne(): void
    {
        $default = DoctrineIntegration::doctrineBundleConfigurationWith(['enable_native_lazy_objects' => true]);
        $named = DoctrineIntegration::doctrineBundleConfigurationForWith('urssaf', ['enable_native_lazy_objects' => true]);

        static::assertArrayNotHasKey('mappings', $named['orm']);
        static::assertSame(['enable_native_lazy_objects', 'entity_managers'], array_keys($named['orm']));
        static::assertSame(['urssaf'], array_keys($named['orm']['entity_managers'] ?? []));
        static::assertSame($default['orm']['mappings'] ?? null, $named['orm']['entity_managers']['urssaf']['mappings'] ?? null);
        static::assertSame($named['orm']['entity_managers'] ?? null, DoctrineIntegration::doctrineBundleConfigurationFor('urssaf')['orm']['entity_managers'] ?? null);
    }
}
