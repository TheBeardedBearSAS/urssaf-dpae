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

use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\BytesType;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositIdType;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;

/**
 * Row of `urssaf_dpae_deposit_flows`: the deposited flow, gzip-compressed, 1:1 with the deposit. Only written for a host
 * that keeps flows (ADR 0008); read and written with DBAL by
 * {@see \TheBeardedBear\UrssafDpae\Bridge\Doctrine\Repository\DoctrineDepositFlowStore}.
 *
 * Not final only because Doctrine may generate a proxy for any mapped entity (pre-generated at cache warm-up, and a proxy
 * cannot extend a readonly class before PHP 8.3): final by convention, never extend it. The identifier is neither
 * readonly nor private: Doctrine assigns it again when a proxy is loaded.
 *
 * @final
 */
class DepositFlowRecord
{
    /** DBAL types of the columns of {@see self::row()}. */
    public const TYPES = [
        'deposit_id' => DepositIdType::NAME,
        'flow' => BytesType::NAME,
    ];

    private function __construct(
        protected DepositId $depositId,
        private readonly string $flow,
    ) {}

    public static function of(DepositId $depositId, string $flow): self
    {
        $compressed = gzencode($flow);
        // Compressing in memory with a valid level cannot fail: only the type of the function admits false.
        \assert(\is_string($compressed), 'gzencode() returns false only for an invalid level.');

        return new self($depositId, $compressed);
    }

    /**
     * @return array{deposit_id: DepositId, flow: string}
     */
    public function row(): array
    {
        return ['deposit_id' => $this->depositId, 'flow' => $this->flow];
    }
}
