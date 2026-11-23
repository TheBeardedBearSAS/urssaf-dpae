<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Bridge\Symfony\Text;

/**
 * Reads and writes (privately) a whole file, turning the failures of the filesystem (a missing file, a directory that is not
 * writable...) into a result instead of a PHP warning on the console.
 */
final class Files
{
    /**
     * @return ?string the contents, null when the file cannot be read
     */
    public static function read(string $path): ?string
    {
        set_error_handler(static fn(): bool => true);
        try {
            $bytes = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        return $bytes === false ? null : $bytes;
    }

    /**
     * Writes the file readable by its owner only (0600): the documents of the package carry personal data. An existing
     * file is replaced.
     *
     * @return bool whether the whole contents were written
     */
    public static function write(string $path, string $bytes): bool
    {
        return self::store($path, $bytes, 'wb');
    }

    /**
     * Like {@see self::write()}, but an existing file is never touched.
     *
     * @return bool whether the file did not exist and the whole contents were written
     */
    public static function create(string $path, string $bytes): bool
    {
        return self::store($path, $bytes, 'xb');
    }

    private static function store(string $path, string $bytes, string $mode): bool
    {
        set_error_handler(static fn(): bool => true);
        try {
            $handle = fopen($path, $mode);
            if ($handle === false) {
                return false;
            }
            // Restricted while the file is still empty (or about to be replaced): the bytes never sit in a readable file.
            $restricted = chmod($path, 0o600);
            $written = fwrite($handle, $bytes);
            $closed = fclose($handle);

            return $restricted && $written === \strlen($bytes) && $closed;
        } finally {
            restore_error_handler();
        }
    }
}
