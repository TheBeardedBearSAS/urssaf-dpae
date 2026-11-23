<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\PayloadNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;

final class ForbidsSerializationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function providePayloads(): iterable
    {
        yield 'flow' => ['flow'];
        yield 'raw document' => ['document'];
        yield 'electronic receipt' => ['receipt'];
        yield 'rejection notice' => ['notice'];
        yield 'return content' => ['content'];
    }

    #[DataProvider('providePayloads')]
    public function testItCannotBeSerialized(string $name): void
    {
        $payload = self::payload($name);

        try {
            serialize($payload);
            static::fail('Expected a refusal.');
        } catch (PayloadNotSerializable $exception) {
            static::assertSame($payload::class . ' must never be serialized.', $exception->getMessage());
        }
    }

    #[DataProvider('providePayloads')]
    public function testItCannotBeUnserialized(string $name): void
    {
        $class = self::payload($name)::class;

        try {
            unserialize('O:' . \strlen($class) . ':"' . $class . '":0:{}');
            static::fail('Expected a refusal.');
        } catch (PayloadNotSerializable $exception) {
            static::assertSame($class . ' must never be unserialized.', $exception->getMessage());
        }
    }

    private static function payload(string $name): object
    {
        $document = RawDocument::of('<envoi/>', 'application/xml');

        return match ($name) {
            'flow' => DpaeFlow::fromXml(
                '<FR_DUE_Upload><FR_DUE_Upload.Test.Indicator>TST</FR_DUE_Upload.Test.Indicator></FR_DUE_Upload>',
                DeclarationMode::Test,
                TestIndicators::defaults(),
            ),
            'document' => $document,
            'receipt' => ElectronicReceipt::of(null, null, null, $document),
            'notice' => RejectionNotice::of(null, null, null, $document, []),
            default => ReturnContent::of('%PDF', 'application/pdf'),
        };
    }
}
