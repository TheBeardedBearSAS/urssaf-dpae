<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Domain\Exception;

/**
 * Reason of a 401 answer, told apart by the realm of the `WWW-Authenticate` header (api-reference.md §1.3, ADR 0001).
 *
 * The value is the realm as published; `Unknown` stands for a missing or unlisted realm.
 */
enum AuthenticationFailure: string
{
    case PasswordExpired = 'Expiration du mot de passe';
    case InvalidCredentials = 'net-entreprises.fr';
    case NotRegistered = 'Utilisateur non-inscrit au service';
    case TokenInvalid = 'Jeton manquant ou invalide';
    case TokenExpired = 'Expiration du jeton';
    case Unknown = 'unknown';

    public static function fromRealm(?string $realm): self
    {
        return $realm === null ? self::Unknown : (self::tryFrom($realm) ?? self::Unknown);
    }

    /**
     * Whether the identity itself is refused: the records of the tenant wait until the credentials are fixed.
     */
    public function isCredentialRefusal(): bool
    {
        return match ($this) {
            self::PasswordExpired, self::InvalidCredentials, self::NotRegistered => true,
            self::TokenInvalid, self::TokenExpired, self::Unknown => false,
        };
    }
}
