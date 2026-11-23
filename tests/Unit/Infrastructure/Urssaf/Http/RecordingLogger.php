<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Tests\Unit\Infrastructure\Urssaf\Http;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * Keeps every log record, to assert what is logged and, above all, what is not.
 */
final class RecordingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => \is_string($level) ? $level : '?', 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: string, message: string, context: array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Everything logged, as one string, to look for secrets in.
     */
    public function dump(): string
    {
        return json_encode($this->records, JSON_THROW_ON_ERROR);
    }
}
