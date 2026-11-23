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

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Optional collaborators of the gateway (see {@see UrssafGatewayFactory}), immutable; each one not given keeps its
 * default: the system clock (UTC), and no logging.
 *
 *     GatewayOptions::defaults()->withLogger($logger)->withClock($clock)
 */
final readonly class GatewayOptions
{
    private function __construct(private ?ClockInterface $clock, private ?LoggerInterface $logger) {}

    public static function defaults(): self
    {
        return new self(null, null);
    }

    public function withClock(ClockInterface $clock): self
    {
        return new self($clock, $this->logger);
    }

    public function withLogger(LoggerInterface $logger): self
    {
        return new self($this->clock, $logger);
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function clock(): ?ClockInterface
    {
        return $this->clock;
    }

    /**
     * Null when not given (the factory default applies).
     */
    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
