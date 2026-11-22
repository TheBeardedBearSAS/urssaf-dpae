<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Application\Service;

use TheBeardedBear\UrssafDpae\Application\Port\DepositRepository;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnNature;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * The flux a lost deposit may have produced (ADR 0005): those with a nature `10` return (receipt or rejection notice)
 * that no local deposit of the tenant holds yet.
 */
final readonly class FluxCandidates
{
    public function __construct(private DepositRepository $deposits) {}

    /**
     * @param list<ReturnReference> $references a listing
     *
     * @return list<ReturnReference> one nature `10` return per unlinked flux, in listing order
     */
    public function among(TenantId $tenantId, array $references): array
    {
        $candidates = [];
        foreach ($references as $reference) {
            $flowKey = $reference->flowId()->value();
            if ($reference->natureCode() !== ReturnNature::ReceiptOrRejection->value || \array_key_exists($flowKey, $candidates)) {
                continue;
            }
            if ($this->deposits->findByFlowId($tenantId, $reference->flowId()) instanceof Deposit) {
                continue;
            }
            $candidates[$flowKey] = $reference;
        }

        return array_values($candidates);
    }
}
