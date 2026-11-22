<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Doctrine\Entity;

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
 * Row of `urssaf_dpae_deposits`. Mapped so that the schema tools and migrations know the table (including the two
 * derived columns the aggregate does not have); written and read with DBAL by
 * {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositRepository}, never through the ORM, so that
 * the optimistic lock and the claim are plain conditional statements and a failed save never closes the entity manager.
 *
 * `openReference` and `openFingerprint` repeat the external reference and the fingerprint while the deposit is open and
 * are `NULL` once it is closed (ADR 0005): with the unique indexes they are a partial uniqueness every database
 * supports.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class DepositRecord
{
    /** DBAL types of the columns of {@see self::row()} (the others are plain strings). */
    public const TYPES = [
        'id' => DepositIdType::NAME,
        'tenant_id' => TenantIdType::NAME,
        'external_reference' => ExternalReferenceType::NAME,
        'fingerprint' => FingerprintType::NAME,
        'declarant_siret' => SiretType::NAME,
        'attempts' => 'integer',
        'claimed_at' => InstantType::NAME,
        'updated_at' => InstantType::NAME,
        'flow_id' => FlowIdType::NAME,
        'result' => DepositResultType::NAME,
        'adopted' => 'boolean',
        'version' => 'integer',
        'open_reference' => ExternalReferenceType::NAME,
        'open_fingerprint' => FingerprintType::NAME,
    ];

    /**
     * @mago-expect lint:excessive-parameter-list One parameter per column of the row of a deposit: the limit (12) applies to the constructors of aggregates, this one only holds the columns
     */
    private function __construct(
        protected DepositId $id,
        private readonly TenantId $tenantId,
        private readonly ExternalReference $externalReference,
        private readonly FlowFingerprint $fingerprint,
        private readonly DeclarationMode $mode,
        private readonly ?Siret $declarantSiret,
        private readonly DepositState $state,
        private readonly int $attempts,
        private readonly Instant $claimedAt,
        private readonly Instant $updatedAt,
        private readonly ?FlowId $flowId,
        private readonly ?DepositResult $result,
        private readonly ?string $lastErrorCode,
        private readonly bool $adopted,
        private readonly int $version,
        private readonly ?ExternalReference $openReference,
        private readonly ?FlowFingerprint $openFingerprint,
    ) {}

    /**
     * The row of the deposit as it is to be stored at `$version`.
     */
    public static function of(Deposit $deposit, int $version): self
    {
        $open = $deposit->isOpen();

        return new self(
            $deposit->id(),
            $deposit->tenantId(),
            $deposit->externalReference(),
            $deposit->fingerprint(),
            $deposit->mode(),
            $deposit->declarantSiret(),
            $deposit->state(),
            $deposit->attempts(),
            $deposit->claimedAt(),
            $deposit->updatedAt(),
            $deposit->flowId(),
            $deposit->result(),
            $deposit->lastErrorCode(),
            $deposit->adoptedByReconciliation(),
            $version,
            $open ? $deposit->externalReference() : null,
            $open ? $deposit->fingerprint() : null,
        );
    }

    /**
     * The columns of the row, keyed by column name (see {@see self::TYPES}).
     *
     * @return array<string, mixed>
     */
    public function row(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'external_reference' => $this->externalReference,
            'fingerprint' => $this->fingerprint,
            'mode' => $this->mode->value,
            'declarant_siret' => $this->declarantSiret,
            'state' => $this->state->value,
            'attempts' => $this->attempts,
            'claimed_at' => $this->claimedAt,
            'updated_at' => $this->updatedAt,
            'flow_id' => $this->flowId,
            'result' => $this->result,
            'last_error_code' => $this->lastErrorCode,
            'adopted' => $this->adopted,
            'version' => $this->version,
            'open_reference' => $this->openReference,
            'open_fingerprint' => $this->openFingerprint,
        ];
    }
}
