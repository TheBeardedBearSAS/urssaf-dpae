<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Authentication;

use SensitiveParameter;
use TheBeardedBear\UrssafDpae\Domain\Exception\CredentialsNotSerializable;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;

/**
 * Opaque token returned by `POST /authentifier_dpae` (api-reference.md §2.1, ADR 0001). It is a credential: it is
 * never logged, never serialized and never cast to a string; `reveal()` is the only way out and is meant for the
 * `Authorization: DPAELogin jeton=<token>` header.
 *
 * The package never decodes the base 64. Leading and trailing ASCII whitespace of the answer body is removed; what
 * remains must contain no control character (CR, LF, NUL, DEL, anything below 0x20), so that the value can never split
 * or inject a header. Nothing else is assumed about the undocumented charset (ADR 0001 amendment).
 *
 * Limit: `var_export()`, an `(array)` cast and reflection bypass `__debugInfo()` and the serialization ban; callers
 * must never export a token.
 */
final readonly class DpaeToken
{
    private function __construct(
        #[SensitiveParameter]
        private string $value,
    ) {}

    public static function fromResponseBody(#[SensitiveParameter] string $body): self
    {
        $value = trim($body, " \t\n\r\v\f");
        if ($value === '') {
            throw InvalidValue::empty('dpae_token');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw InvalidValue::invalidFormat('dpae_token', 'no control characters');
        }

        return new self($value);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
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
}
