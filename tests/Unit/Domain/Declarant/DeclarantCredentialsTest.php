<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Domain\Declarant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Exception\CredentialsNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

final class DeclarantCredentialsTest extends TestCase
{
    private const CANARY = 'Example-Passw0rd';

    public function testAccessorsReturnTheGivenValues(): void
    {
        $credentials = DeclarantCredentials::of(
            Siret::fromString('99999999999999'),
            'John',
            'Doe',
            self::CANARY,
            ServiceCode::fromInt(97),
        );

        static::assertSame('99999999999999', $credentials->siret()->value());
        static::assertSame('John', $credentials->nom());
        static::assertSame('Doe', $credentials->prenom());
        static::assertSame(self::CANARY, $credentials->motdepasse());
        static::assertSame(97, $credentials->service()->value());
    }

    public function testBoundsAreAccepted(): void
    {
        $credentials = DeclarantCredentials::of(
            Siret::fromString('99999999999999'),
            str_repeat('é', 64),
            str_repeat('é', 39),
            str_repeat('p', 30),
            ServiceCode::direct(),
        );

        static::assertSame(64, mb_strlen($credentials->nom()));
        static::assertSame(39, mb_strlen($credentials->prenom()));
        static::assertSame(30, mb_strlen($credentials->motdepasse()));
        static::assertSame(str_repeat('é', 8), DeclarantCredentials::of(Siret::fromString('99999999999999'), 'A', 'B', str_repeat('é', 8), ServiceCode::direct())->motdepasse());
        static::assertSame('pppppppp', (DeclarantCredentials::of(Siret::fromString('99999999999999'), 'A', 'B', 'pppppppp', ServiceCode::direct()))->motdepasse());
    }

    /**
     * @return iterable<string, array{string, string, string, string, array<string, scalar>}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'empty name' => ['', 'Doe', self::CANARY, 'declarant_name.empty', []];
        yield 'name too long' => [str_repeat('a', 65), 'Doe', self::CANARY, 'declarant_name.too_long', ['max_length' => 64]];
        yield 'name invalid utf-8' => ["\xFF", 'Doe', self::CANARY, 'declarant_name.invalid_format', ['expected_format' => 'UTF-8']];
        yield 'empty first name' => ['John', '', self::CANARY, 'declarant_first_name.empty', []];
        yield 'first name too long' => ['John', str_repeat('a', 40), self::CANARY, 'declarant_first_name.too_long', ['max_length' => 39]];
        yield 'password invalid utf-8 at the minimum length' => ['John', 'Doe', "\xFF" . str_repeat('p', 7), 'password.invalid_format', ['expected_format' => 'UTF-8']];
        yield 'first name invalid utf-8' => ['John', "\xFF", self::CANARY, 'declarant_first_name.invalid_format', ['expected_format' => 'UTF-8']];
        yield 'password multibyte too short' => ['John', 'Doe', str_repeat('é', 7), 'password.too_short', ['min_length' => 8]];
        yield 'name multibyte too long' => [str_repeat('é', 65), 'Doe', self::CANARY, 'declarant_name.too_long', ['max_length' => 64]];
        yield 'password too short' => ['John', 'Doe', str_repeat('p', 7), 'password.too_short', ['min_length' => 8]];
        yield 'empty password' => ['John', 'Doe', '', 'password.too_short', ['min_length' => 8]];
        yield 'password too long' => ['John', 'Doe', str_repeat('p', 31), 'password.too_long', ['max_length' => 30]];
    }

    /**
     * @param array<string, scalar> $params
     */
    #[DataProvider('provideRefusals')]
    public function testSpecificationLengthsAreEnforced(string $nom, string $prenom, string $candidate, string $code, array $params): void
    {
        try {
            DeclarantCredentials::of(Siret::fromString('99999999999999'), $nom, $prenom, $candidate, ServiceCode::direct());
            static::fail('Expected a refusal.');
        } catch (InvalidValue $exception) {
            static::assertSame($code, $exception->violationCode());
            static::assertSame($params, $exception->params());
            static::assertStringNotContainsString($candidate === '' ? "\0" : $candidate, $exception->getMessage());
        }
    }

    public function testNamesAreNotCheckedAgainstAPattern(): void
    {
        $credentials = DeclarantCredentials::of(Siret::fromString('99999999999999'), "Jean-Éric d'Ô 3", '1', self::CANARY, ServiceCode::direct());

        static::assertSame("Jean-Éric d'Ô 3", $credentials->nom());
    }

    public function testDebugInfoMasksPersonalAndSecretFields(): void
    {
        $credentials = DeclarantCredentials::of(Siret::fromString('99999999999999'), 'John', 'Doe', self::CANARY, ServiceCode::fromInt(97));

        static::assertSame(
            ['siret' => '99999999999999', 'service' => 97, 'nom' => '***', 'prenom' => '***', 'motdepasse' => '***'],
            $credentials->__debugInfo(),
        );
        static::assertStringNotContainsString('John', print_r($credentials, true));
        static::assertStringNotContainsString(self::CANARY, print_r($credentials, true));
    }

    public function testItCannotBeSerialized(): void
    {
        $credentials = DeclarantCredentials::of(Siret::fromString('99999999999999'), 'John', 'Doe', self::CANARY, ServiceCode::direct());

        try {
            serialize($credentials);
            static::fail('Expected a refusal.');
        } catch (CredentialsNotSerializable $exception) {
            static::assertSame(DeclarantCredentials::class . ' must never be serialized.', $exception->getMessage());
        }
    }

    public function testItCannotBeUnserialized(): void
    {
        try {
            unserialize('O:' . \strlen(DeclarantCredentials::class) . ':"' . DeclarantCredentials::class . '":0:{}');
            static::fail('Expected a refusal.');
        } catch (CredentialsNotSerializable $exception) {
            static::assertSame(DeclarantCredentials::class . ' must never be unserialized.', $exception->getMessage());
        }
    }
}
