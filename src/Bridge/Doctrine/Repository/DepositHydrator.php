<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository;

use ReflectionClass;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositResultType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\ExternalReferenceType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FingerprintType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\FlowIdType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\InstantType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\SiretType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\TenantIdType;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositState;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Rebuilds a stored {@see Deposit}. The aggregate has no named constructor for a stored state (its transitions only go
 * forward from `pending`), so the row is written back property by property, as Doctrine's own hydration does: the
 * pending events are left at their default, none.
 */
final class DepositHydrator
{
    /**
     * The properties of {@see Deposit} written from the row: every one but the pending events (a test compares them).
     */
    public const PROPERTIES = [
        'id', 'tenantId', 'externalReference', 'fingerprint', 'mode', 'declarantSiret', 'state', 'attempts', 'claimedAt',
        'updatedAt', 'flowId', 'result', 'lastErrorCode', 'adoptedByReconciliation', 'version',
    ];

    public static function hydrate(Row $row): Deposit
    {
        $class = new ReflectionClass(Deposit::class);
        $deposit = $class->newInstanceWithoutConstructor();
        $values = [
            'id' => $row->object('id', DepositIdType::NAME, DepositId::class),
            'tenantId' => $row->object('tenant_id', TenantIdType::NAME, TenantId::class),
            'externalReference' => $row->object('external_reference', ExternalReferenceType::NAME, ExternalReference::class),
            'fingerprint' => $row->object('fingerprint', FingerprintType::NAME, FlowFingerprint::class),
            'mode' => DeclarationMode::from($row->string('mode')),
            'declarantSiret' => $row->nullableObject('declarant_siret', SiretType::NAME, Siret::class),
            'state' => DepositState::from($row->string('state')),
            'attempts' => $row->int('attempts'),
            'claimedAt' => $row->object('claimed_at', InstantType::NAME, Instant::class),
            'updatedAt' => $row->object('updated_at', InstantType::NAME, Instant::class),
            'flowId' => $row->nullableObject('flow_id', FlowIdType::NAME, FlowId::class),
            'result' => $row->nullableObject('result', DepositResultType::NAME, DepositResult::class),
            'lastErrorCode' => $row->nullableString('last_error_code'),
            'adoptedByReconciliation' => $row->bool('adopted'),
            'version' => $row->int('version'),
        ];
        foreach (self::PROPERTIES as $property) {
            $class->getProperty($property)->setValue($deposit, $values[$property]);
        }

        return $deposit;
    }
}
