<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Bridge\Symfony\Text;

use PHPUnit\Framework\TestCase;
use TheBeardedBear\UrssafDpae\Bridge\Symfony\Text\Files;
use TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support\Workspace;
use ValueError;

final class FilesTest extends TestCase
{
    public function testAFileIsReadAndWritten(): void
    {
        $files = new Workspace();

        static::assertTrue(Files::write($files->path('a.txt'), 'contents'));
        static::assertSame('contents', Files::read($files->path('a.txt')));
        static::assertTrue(Files::write($files->path('a.txt'), ''));
        static::assertSame('', Files::read($files->path('a.txt')));
    }

    public function testAFileIsWrittenReadableByItsOwnerOnlyWhateverTheUmask(): void
    {
        $files = new Workspace();
        $previous = umask(0);
        try {
            static::assertTrue(Files::write($files->path('new.txt'), 'contents'));
            $existing = $files->write('existing.txt', 'old');
            chmod($existing, 0o666);
            static::assertTrue(Files::write($existing, 'contents'));
        } finally {
            static::assertSame(0, umask($previous));
        }

        static::assertSame(0o600, fileperms($files->path('new.txt')) & 0o777);
        static::assertSame(0o600, fileperms($files->path('existing.txt')) & 0o777);
        static::assertSame($previous, umask($previous));
    }

    public function testAnExistingFileIsKeptWhenOverwritingIsRefused(): void
    {
        $files = new Workspace();
        $existing = $files->write('existing.txt', 'old');

        static::assertFalse(Files::create($existing, 'new'));
        static::assertSame('old', Files::read($existing));
        static::assertTrue(Files::create($files->path('fresh.txt'), 'new'));
        static::assertSame('new', Files::read($files->path('fresh.txt')));
    }

    public function testAFileWhoseModeCannotBeRestrictedIsNotReportedAsWritten(): void
    {
        // /dev/null is writable by everybody and owned by root: a regular user can write to it but cannot chmod it.
        if (!is_writable('/dev/null') || (\function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            static::markTestSkipped('Needs a writable file that the user does not own (a non-root user and /dev/null).');
        }

        static::assertFalse(Files::write('/dev/null', 'contents'));
    }

    public function testAMissingFileCannotBeRead(): void
    {
        $files = new Workspace();

        static::assertNull(Files::read($files->path('missing.txt')));
    }

    public function testNothingCanBeWrittenWhereThereIsNoFileToWrite(): void
    {
        $files = new Workspace();

        static::assertFalse(Files::write($files->path('missing-directory/a.txt'), 'contents'));
        static::assertFalse(Files::write($files->path('.'), 'contents'));
    }

    public function testTheWarningsOfThePhpFunctionsAreSwallowed(): void
    {
        $files = new Workspace();
        error_clear_last();

        Files::read($files->path('missing.txt'));
        static::assertNull(error_get_last());

        Files::write($files->path('missing-directory/a.txt'), 'contents');
        static::assertNull(error_get_last());
    }

    public function testTheErrorHandlerIsRestoredWhenThePathIsRefusedByPhp(): void
    {
        $previous = static fn(): bool => true;
        set_error_handler($previous);
        $this->expectException(ValueError::class);
        try {
            Files::read("with\0null");
        } finally {
            $current = set_error_handler(null);
            restore_error_handler();
            restore_error_handler();
            static::assertSame($previous, $current);
        }
    }

    public function testThePreviousErrorHandlerIsRestoredAndTheWarningsAreSwallowed(): void
    {
        $files = new Workspace();
        $seen = 0;
        $previous = static function () use (&$seen): bool {
            ++$seen;

            return true;
        };
        set_error_handler($previous);
        try {
            Files::read($files->path('missing.txt'));
            Files::write($files->path('missing-directory/a.txt'), 'contents');

            $current = set_error_handler(null);
            restore_error_handler();
            static::assertSame($previous, $current);
            static::assertSame(0, $seen);
        } finally {
            restore_error_handler();
        }
    }
}
