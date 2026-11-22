<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Service;

use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Event\DepositReceived as DomainReceived;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

trait IntegrationEventTranslatorFixtures
{
    private const AT = '2022-02-01T12:00:00Z';
    private const ID_FORMAT = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    private static function received(?FlowId $flowId): DomainReceived
    {
        return new DomainReceived(DepositId::fromString('d1'), Samples::tenant('tenant-1'), Samples::reference('ref-1'), $flowId, Samples::at(self::AT));
    }
}
