<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnIdentity;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Return\TrackedReturn;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * Fictitious deposits in every state of the lifecycle and fictitious returns, for the persistence tests. Deposits are
 * returned without pending events (a stored deposit has none).
 *
 * @mago-expect lint:too-many-methods One named fixture per state of the lifecycle and per kind of return
 */
final class Cases
{
    public const T0 = '2022-02-01T10:00:00Z';
    public const T1 = '2022-02-01T10:01:00Z';
    public const T2 = '2022-02-01T10:02:00Z';

    /**
     * An ISO-8859-1 document: the byte `0xE9` is not valid UTF-8.
     */
    public static function document(): RawDocument
    {
        return RawDocument::of("<envoi>\xE9t\xE9</envoi>", 'application/xml; charset=ISO-8859-1');
    }

    public static function pending(string $id = 'dep-a', string $tenant = 't1', string $at = self::T0): Deposit
    {
        return Samples::deposit($id, $tenant, 'ref-' . $id, 'variant-' . $id, $at);
    }

    public static function depositing(string $id = 'dep-a', string $tenant = 't1', string $claimedAt = self::T1): Deposit
    {
        $deposit = self::pending($id, $tenant);
        $deposit->startDeposit(Samples::at($claimedAt), 3);

        return self::quiet($deposit);
    }

    public static function received(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->recordResult(
            ElectronicReceipt::of(FlowId::fromString('flow-' . $id), Siret::fromString(Samples::SIRET), Samples::at(self::T2), self::document()),
            Samples::at(self::T2),
        );

        return self::quiet($deposit);
    }

    public static function receivedWithoutFlowId(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->recordResult(ElectronicReceipt::of(null, null, null, self::document()), Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function rejected(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->recordResult(
            RejectionNotice::of(FlowId::fromString('flow-' . $id), null, Samples::at(self::T2), self::document(), ['01', 'motif é']),
            Samples::at(self::T2),
        );

        return self::quiet($deposit);
    }

    public static function failed(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->markFailed('deposit.bad_request', Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function notSent(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->markNotSent('deposit.not_sent', 3, Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function invalidCredentials(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->markInvalidCredentials(Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function outcomeUnknown(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::depositing($id, $tenant);
        $deposit->markOutcomeUnknown('deposit.outcome_unknown', Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function adopted(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::outcomeUnknown($id, $tenant);
        $deposit->adoptAsReceived(FlowId::fromString('flow-' . $id), Samples::at(self::T2));

        return self::quiet($deposit);
    }

    public static function notReceived(string $id = 'dep-a', string $tenant = 't1'): Deposit
    {
        $deposit = self::outcomeUnknown($id, $tenant);
        $deposit->resolveAsNotReceived(Samples::at(self::T2));

        return self::quiet($deposit);
    }

    /**
     * A deposit of the tenant in the state, claimed at the instant (the claim instant of a `pending` one is its creation).
     */
    public static function inState(string $state, string $id, string $tenant, string $claimedAt): Deposit
    {
        $deposit = Samples::deposit($id, $tenant, 'ref-' . $id, 'variant-' . $id, $claimedAt);
        if ($state === 'pending') {
            return $deposit;
        }
        $deposit->startDeposit(Samples::at($claimedAt), 3);
        self::finish($deposit, $state);

        return self::quiet($deposit);
    }

    public static function tenant(string $tenant): TenantId
    {
        return Samples::tenant($tenant);
    }

    public static function depositId(string $id): DepositId
    {
        return DepositId::fromString($id);
    }

    public static function reference(string $id): ExternalReference
    {
        return Samples::reference('ref-' . $id);
    }

    public static function listed(string $flowId, int $nature, ?string $ops, string $status = 'OK'): ReturnReference
    {
        return ReturnReference::fromListing(
            Samples::flowId($flowId),
            $nature,
            $status,
            '20220201100000',
            '20220201090000',
            Samples::at(self::T1),
            Samples::at(self::T0),
            '7',
            $ops,
            'delegate-1',
            'https://telechargement.example.test/retour/7',
        );
    }

    public static function listedWithoutDocument(string $flowId, int $nature, ?string $ops): ReturnReference
    {
        return ReturnReference::fromListing(
            Samples::flowId($flowId),
            $nature,
            'EN_COURS',
            '20220201100000',
            '20220201090000',
            Samples::at(self::T1),
            Samples::at(self::T0),
            null,
            $ops,
            null,
            null,
        );
    }

    public static function identity(string $flowId, int $nature, ?string $ops): ReturnIdentity
    {
        return self::listed($flowId, $nature, $ops)->identity();
    }

    public static function discovered(string $tenant, ReturnReference $reference): TrackedReturn
    {
        $tracked = TrackedReturn::discover(self::tenant($tenant), $reference, null, Samples::at(self::T1));
        $tracked->releaseEvents();

        return $tracked;
    }

    public static function discoveredFor(string $tenant, ReturnReference $reference, string $depositId): TrackedReturn
    {
        $tracked = TrackedReturn::discover(self::tenant($tenant), $reference, self::depositId($depositId), Samples::at(self::T1));
        $tracked->releaseEvents();

        return $tracked;
    }

    private static function finish(Deposit $deposit, string $state): void
    {
        $at = Samples::at(self::T2);
        if ($state === 'received') {
            $deposit->recordResult(ElectronicReceipt::of(null, null, null, self::document()), $at);

            return;
        }
        if ($state === 'rejected') {
            $deposit->recordResult(RejectionNotice::of(null, null, null, self::document(), []), $at);

            return;
        }
        if ($state === 'failed') {
            $deposit->markFailed('deposit.bad_request', $at);

            return;
        }
        if ($state === 'invalid_credentials') {
            $deposit->markInvalidCredentials($at);

            return;
        }
        if ($state === 'outcome_unknown' || $state === 'not_received') {
            $deposit->markOutcomeUnknown('deposit.outcome_unknown', $at);
        }
        if ($state === 'not_received') {
            $deposit->resolveAsNotReceived($at);
        }
    }

    private static function quiet(Deposit $deposit): Deposit
    {
        $deposit->releaseEvents();

        return $deposit;
    }
}
