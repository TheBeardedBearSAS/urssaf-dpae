<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Type;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Doctrine\Type\DepositResultType;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ElectronicReceipt;
use TheBeardedBear\UrssafDpae\Domain\Deposit\FlowId;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RawDocument;
use TheBeardedBear\UrssafDpae\Domain\Deposit\RejectionNotice;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Cases;
use TheBeardedBear\UrssafDpae\Tests\Functional\Doctrine\Support\Json;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Outcome;
use TheBeardedBear\UrssafDpae\Tests\Unit\Application\Fake\Samples;

/**
 * @mago-expect lint:too-many-methods One method per rule of the JSON column type
 */
final class DepositResultTypeTest extends TestCase
{
    private SQLitePlatform $platform;

    protected function setUp(): void
    {
        $this->platform = new SQLitePlatform();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function results(): Iterator
    {
        foreach (['receipt with every field', 'receipt with nothing but the document', 'rejection with reasons', 'rejection without reasons'] as $case) {
            yield $case => [$case];
        }
    }

    /**
     * @return Iterator<string, array{array<string, mixed>|string}> the document with one fault: a raw string, or the
     *                                                              valid fields amended by an array
     */
    public static function invalidDocuments(): Iterator
    {
        yield 'not JSON' => ['{'];
        yield 'a JSON string' => ['"receipt"'];
        yield 'an unknown kind' => [['kind' => 'other']];
        yield 'a missing kind' => [['kind' => null]];
        yield 'a flow id that is not a string' => [['flowId' => 12]];
        yield 'a flow id that is not valid' => [['flowId' => '']];
        yield 'a siret that is not valid' => [['siret' => '1']];
        yield 'an instant that is not valid' => [['generatedAt' => 'yesterday']];
        yield 'a missing content type' => [['contentType' => null]];
        yield 'a document that is not base 64' => [['document' => '***']];
        yield 'an empty document' => [['document' => '']];
        yield 'a missing document' => [['document' => null]];
        yield 'reasons that are not a list' => [['kind' => 'rejection', 'reasons' => 'a']];
        yield 'reasons that are not a sequence' => [['kind' => 'rejection', 'reasons' => ['a' => 'b']]];
        yield 'reasons that are not strings' => [['kind' => 'rejection', 'reasons' => [1]]];
    }

    private static function sampleResult(string $case): DepositResult
    {
        return match ($case) {
            'receipt with every field' => ElectronicReceipt::of(FlowId::fromString('flow-1'), Siret::fromString(Samples::SIRET), Samples::at(Cases::T1), Cases::document()),
            'receipt with nothing but the document' => ElectronicReceipt::of(null, null, null, RawDocument::of('<envoi/>', 'text/xml')),
            'rejection with reasons' => RejectionNotice::of(FlowId::fromString('flow-1'), Siret::fromString(Samples::SIRET), Samples::at(Cases::T1), Cases::document(), ['01', 'é']),
            default => RejectionNotice::of(null, null, null, Cases::document(), []),
        };
    }

    #[DataProvider('results')]
    public function testAResultIsStoredAsJsonAndReadBack(string $case): void
    {
        $result = self::sampleResult($case);
        $type = new DepositResultType();

        $stored = $type->convertToDatabaseValue($result, $this->platform);

        static::assertIsString($stored);
        static::assertEquals($result, $type->convertToPHPValue($stored, $this->platform));
        static::assertSame($result->document()->bytes(), $type->convertToPHPValue($stored, $this->platform)?->document()->bytes());
        static::assertSame($result instanceof RejectionNotice ? 'rejection' : 'receipt', Json::decode($stored)['kind']);
    }

    public function testNullStaysNullAndTheDeclarationIsAClob(): void
    {
        $type = new DepositResultType();

        static::assertNull($type->convertToDatabaseValue(null, $this->platform));
        static::assertNull($type->convertToPHPValue(null, $this->platform));
        static::assertSame('CLOB', $type->getSQLDeclaration([], $this->platform));
    }

    public function testAnythingButAResultIsRefusedWhenWriting(): void
    {
        $failure = Outcome::of(fn(): ?string => (new DepositResultType())->convertToDatabaseValue('receipt', $this->platform));

        static::assertInstanceOf(InvalidType::class, $failure);
        static::assertSame("Could not convert PHP value 'receipt' to type " . DepositResultType::class . '. Expected one of the following types: ' . DepositResult::class . ', null.', $failure->getMessage());
    }

    public function testANonStringIsRefusedWhenReading(): void
    {
        $failure = Outcome::of(fn(): ?DepositResult => (new DepositResultType())->convertToPHPValue(12, $this->platform));

        static::assertInstanceOf(InvalidType::class, $failure);
        static::assertSame('Could not convert PHP value 12 to type ' . DepositResultType::class . '. Expected one of the following types: string, null.', $failure->getMessage());
    }

    /**
     * @return Iterator<string, array{array<string, mixed>, string}>
     */
    public static function missingValues(): Iterator
    {
        yield 'a kind that is not a string' => [['kind' => 12], 'kind as a string'];
        yield 'a flow id that is not a string' => [['flowId' => 12], 'flowId as a string'];
        yield 'a content type that is not a string' => [['contentType' => null], 'contentType as a string'];
        yield 'a document that is not a string' => [['document' => []], 'document as a string'];
    }

    /**
     * @param array<string, mixed> $fault
     */
    #[DataProvider('missingValues')]
    public function testTheFieldThatIsNotAStringIsNamed(array $fault, string $expected): void
    {
        $valid = ['kind' => 'receipt', 'flowId' => null, 'siret' => null, 'generatedAt' => null, 'contentType' => 'text/xml', 'document' => base64_encode('<a/>'), 'reasons' => []];

        $failure = Outcome::of(fn(): ?DepositResult => (new DepositResultType())->convertToPHPValue(json_encode($fault + $valid, \JSON_THROW_ON_ERROR), $this->platform));

        static::assertInstanceOf(InvalidFormat::class, $failure);
        $cause = $failure->getPrevious();
        static::assertInstanceOf(InvalidValue::class, $cause);
        static::assertSame(['expected_format' => $expected], $cause->params());
    }

    /**
     * @param array<string, mixed>|string $fault
     */
    #[DataProvider('invalidDocuments')]
    public function testAnInvalidDocumentIsRefusedWhenReading(array|string $fault): void
    {
        $valid = ['kind' => 'receipt', 'flowId' => null, 'siret' => null, 'generatedAt' => null, 'contentType' => 'text/xml', 'document' => base64_encode('<a/>'), 'reasons' => []];
        $document = \is_string($fault) ? $fault : json_encode($fault + $valid, \JSON_THROW_ON_ERROR);

        static::assertInstanceOf(InvalidFormat::class, Outcome::of(fn(): ?DepositResult => (new DepositResultType())->convertToPHPValue($document, $this->platform)));
    }
}
