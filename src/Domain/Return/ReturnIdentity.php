<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Return;

use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;

/**
 * Identity of a return within a tenant: flow, raw nature code and OPS. An absent OPS (`null`, a return by the SI DPAE)
 * differs from any present one; the values are compared as stored, never concatenated.
 */
final readonly class ReturnIdentity
{
    public function __construct(
        private FlowId $flowId,
        private int $natureCode,
        private ?string $ops,
    ) {}

    public function flowId(): FlowId
    {
        return $this->flowId;
    }

    public function natureCode(): int
    {
        return $this->natureCode;
    }

    public function ops(): ?string
    {
        return $this->ops;
    }

    public function equals(self $other): bool
    {
        return $this->flowId->equals($other->flowId) && $this->natureCode === $other->natureCode && $this->ops === $other->ops;
    }
}
