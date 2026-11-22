<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Functional\Symfony\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A throw-away directory under var/ (never the system temporary directory), removed when the object is destroyed.
 */
final readonly class Workspace
{
    private string $directory;

    public function __construct()
    {
        $this->directory = \dirname(__DIR__, 4) . '/var/t8/files/' . bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($this->directory);
    }

    public function __destruct()
    {
        (new Filesystem())->remove($this->directory);
    }

    public function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }

    public function write(string $name, string $contents): string
    {
        $path = $this->path($name);
        Assert::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    public function read(string $name): string
    {
        $contents = file_get_contents($this->path($name));
        Assert::assertNotFalse($contents);

        return $contents;
    }
}
