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

use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;

/**
 * Fictitious answers of URSSAF to a deposit, shared by the application tests.
 */
final class SampleAnswers
{
    public static function receipt(string $flowId): ElectronicReceipt
    {
        return ElectronicReceipt::of(Samples::flowId($flowId), null, Samples::at(Samples::NOW), RawDocument::of('<envoi/>', 'application/xml'));
    }

    public static function receiptWithoutFlowId(): ElectronicReceipt
    {
        return ElectronicReceipt::of(null, null, null, RawDocument::of('<envoi/>', 'application/xml'));
    }

    public static function rejectionWithoutFlowId(): RejectionNotice
    {
        return RejectionNotice::of(null, null, null, RawDocument::of('<envoi/>', 'application/xml'), []);
    }

    public static function rejection(string $flowId): RejectionNotice
    {
        return RejectionNotice::of(Samples::flowId($flowId), null, Samples::at(Samples::NOW), RawDocument::of('<envoi/>', 'application/xml'), ['01']);
    }
}
