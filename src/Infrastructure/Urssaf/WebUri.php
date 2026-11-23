<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf;

/**
 * Reads an absolute `http` or `https` URI the gateway may send a token to (ADR 0002). Anything that could make the
 * host ambiguous is refused: credentials (`https://good@evil/`), whitespace, control characters, a backslash, a port 0,
 * a relative or protocol-relative reference, another scheme.
 *
 * @internal
 */
final class WebUri
{
    private const FORBIDDEN_CHARACTERS = '/[\x00-\x20\x7F\\\\]/';

    /**
     * @return array{scheme: string, host: string, port: int|null}|null scheme in lower case; null when `$uri` is not an
     *                                                                  absolute web URI
     */
    public static function parse(string $uri): ?array
    {
        $parsed = parse_url($uri);
        $parts = \is_array($parsed) ? $parsed : [];
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        $port = $parts['port'] ?? null;
        if (
            !\in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || \array_key_exists('user', $parts)
            || $port === 0
            || preg_match(self::FORBIDDEN_CHARACTERS, $uri) === 1
        ) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => $host, 'port' => $port];
    }

    /**
     * Normalised `scheme://host:port`: lower-case scheme and host, the default port made explicit (`443` for `https`,
     * `80` for `http`). Null when `$uri` is not an absolute web URI.
     */
    public static function originOf(string $uri): ?string
    {
        $parts = self::parse($uri);
        if ($parts === null) {
            return null;
        }

        return \sprintf(
            '%s://%s:%d',
            $parts['scheme'],
            strtolower($parts['host']),
            $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80),
        );
    }
}
