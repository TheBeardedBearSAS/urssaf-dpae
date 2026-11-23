<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake;

use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\Deposit;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowFingerprint;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;

/**
 * Fictitious values shared by the application tests.
 */
final class Samples
{
    public const NOW = '2022-02-01T12:00:00Z';
    public const SIRET = '12345678901234';

    public static function tenant(string $tenant): TenantId
    {
        return TenantId::fromString($tenant);
    }

    public static function at(string $iso8601): Instant
    {
        return Instant::fromIso8601($iso8601);
    }

    public static function reference(string $reference): ExternalReference
    {
        return ExternalReference::fromString($reference);
    }

    public static function flowId(string $id): FlowId
    {
        return FlowId::fromString($id);
    }

    /**
     * A flow whose bytes (and so fingerprint) depend on the variant.
     */
    public static function flow(DeclarationMode $mode, string $variant): DpaeFlow
    {
        $indicator = $mode === DeclarationMode::Real ? '120' : 'TST';

        return DpaeFlow::fromXml(
            '<FR_DUE_Upload><FR_DUE_Upload.Test.Indicator>' . $indicator . '</FR_DUE_Upload.Test.Indicator><Variant>' . $variant . '</Variant></FR_DUE_Upload>',
            $mode,
            TestIndicators::defaults(),
        );
    }

    public static function deposit(string $id, string $tenant, string $reference, string $variant, string $at): Deposit
    {
        return Deposit::request(
            DepositId::fromString($id),
            self::tenant($tenant),
            self::reference($reference),
            self::flow(DeclarationMode::Test, $variant)->fingerprint(),
            DeclarationMode::Test,
            Siret::fromString(self::SIRET),
            self::at($at),
        );
    }

    public static function fingerprint(string $variant): FlowFingerprint
    {
        return self::flow(DeclarationMode::Test, $variant)->fingerprint();
    }
}
