<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Declarant;

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Exception\CredentialsNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * The five fields of the `identifiants` authentication body (api-reference.md §2.1, ADR 0001). One instance is one
 * authenticating identity: a direct declarant or a concentrator.
 *
 * Only the published Swagger lengths are checked locally: `nom` 1-64, `prenom` 1-39, `motdepasse` 8-30 characters. The
 * published name pattern lost its accented letters, so it is not applied here; URSSAF checks it (422).
 *
 * The password is never exposed by `__debugInfo()` nor serialization, and no property is public. The three secret or
 * personal parameters are `#[SensitiveParameter]`, so they are redacted from stack traces.
 *
 * Limit: `var_export()`, an `(array)` cast and reflection bypass `__debugInfo()` and the serialization ban; callers
 * must never export credentials.
 */
final readonly class DeclarantCredentials
{
    private const NAME_MAX = 64;
    private const FIRST_NAME_MAX = 39;
    private const PASSWORD_MIN = 8;
    private const PASSWORD_MAX = 30;

    private function __construct(
        private Siret $siret,
        #[SensitiveParameter]
        private string $nom,
        #[SensitiveParameter]
        private string $prenom,
        #[SensitiveParameter]
        private string $motdepasse,
        private ServiceCode $service,
    ) {}

    /**
     * @throws InvalidValue `declarant_name.*`, `declarant_first_name.*` or `password.*`
     */
    public static function of(
        Siret $siret,
        #[SensitiveParameter]
        string $nom,
        #[SensitiveParameter]
        string $prenom,
        #[SensitiveParameter]
        string $motdepasse,
        ServiceCode $service,
    ): self {
        self::assertLength('declarant_name', $nom, 1, self::NAME_MAX);
        self::assertLength('declarant_first_name', $prenom, 1, self::FIRST_NAME_MAX);
        self::assertLength('password', $motdepasse, self::PASSWORD_MIN, self::PASSWORD_MAX);

        return new self($siret, $nom, $prenom, $motdepasse, $service);
    }

    public function siret(): Siret
    {
        return $this->siret;
    }

    public function nom(): string
    {
        return $this->nom;
    }

    public function prenom(): string
    {
        return $this->prenom;
    }

    public function motdepasse(): string
    {
        return $this->motdepasse;
    }

    public function service(): ServiceCode
    {
        return $this->service;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'siret' => $this->siret->value(),
            'service' => $this->service->value(),
            'nom' => '***',
            'prenom' => '***',
            'motdepasse' => '***',
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw CredentialsNotSerializable::serializing(self::class);
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw CredentialsNotSerializable::unserializing(self::class);
    }

    private static function assertLength(string $subject, #[SensitiveParameter] string $value, int $min, int $max): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidValue::invalidFormat($subject, 'UTF-8');
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min) {
            throw $min === 1 ? InvalidValue::empty($subject) : InvalidValue::tooShort($subject, $min);
        }
        if ($length > $max) {
            throw InvalidValue::tooLong($subject, $max);
        }
    }
}
