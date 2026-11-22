<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpaeHandler;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Lock\OperationLocks;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Deposits the flow of the message. A refusal or a rejection is a deposit state, not a failure of the message; a flow that is
 * not valid for the mode of the message is unrecoverable (the same message would fail again).
 */
final readonly class DepositDpaeMessageHandler
{
    public function __construct(
        private DepositDpaeHandler $deposit,
        private TestIndicators $indicators,
        private OperationLocks $locks,
    ) {}

    public function __invoke(DepositDpaeMessage $message): void
    {
        // Validated before the lock: its name is built from the validated tenant and reference only.
        $command = MessageFailures::guard(fn(): DepositDpae => self::command($message, $this->indicators));
        $this->locks->exclusively(
            'deposit',
            [$command->tenantId->value(), $command->externalReference->value()],
            fn(): mixed => MessageFailures::guard(fn(): mixed => ($this->deposit)($command)),
            static fn(string $resource): never => throw MessageFailures::busy($resource),
        );
    }

    private static function command(DepositDpaeMessage $message, TestIndicators $indicators): DepositDpae
    {
        $mode = DeclarationMode::tryFrom($message->mode) ?? throw new UnrecoverableMessageHandlingException('The mode of the message must be "test" or "real".');
        $bytes = base64_decode($message->flow, true);
        if ($bytes === false) {
            throw new UnrecoverableMessageHandlingException('The flow of the message is not base 64 encoded.');
        }

        return new DepositDpae(
            TenantId::fromString($message->tenantId),
            ExternalReference::fromString($message->externalReference),
            DpaeFlow::fromXml($bytes, $mode, $indicators),
        );
    }
}
