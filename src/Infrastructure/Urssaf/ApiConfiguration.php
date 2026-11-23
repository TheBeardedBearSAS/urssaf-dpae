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

use DateTimeZone;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

/**
 * Immutable settings of the URSSAF gateway: `defaults()` plus `with*()`. The defaults that are not specification values
 * (response limit, content type, time zone) are listed in api-reference.md section 5.
 */
final readonly class ApiConfiguration
{
    public const DEFAULT_DEPOSIT_CONTENT_TYPE = 'text/plain';
    public const DEFAULT_MAX_FLOW_BYTES = 100_000_000;
    public const DEFAULT_MAX_RESPONSE_BYTES = 100_000_000;
    public const USER_AGENT = 'thebeardedbear-urssaf-dpae';

    private function __construct(
        private ServiceEndpoints $endpoints,
        private string $authenticationEncoding,
        private string $depositContentType,
        private ?int $maxFlowBytes,
        private ?int $maxResponseBytes,
        private DateTimeZone $returnsTimeZone,
        private DeclarationMode $declarationMode,
    ) {}

    public static function defaults(): self
    {
        return new self(
            ServiceEndpoints::defaults(),
            IdentifiantsXmlWriter::DEFAULT_ENCODING,
            self::DEFAULT_DEPOSIT_CONTENT_TYPE,
            self::DEFAULT_MAX_FLOW_BYTES,
            self::DEFAULT_MAX_RESPONSE_BYTES,
            new DateTimeZone(WireDate::DEFAULT_TIME_ZONE),
            DeclarationMode::Test,
        );
    }

    public function withEndpoints(ServiceEndpoints $endpoints): self
    {
        return new self(
            $endpoints,
            $this->authenticationEncoding,
            $this->depositContentType,
            $this->maxFlowBytes,
            $this->maxResponseBytes,
            $this->returnsTimeZone,
            $this->declarationMode,
        );
    }

    /**
     * XML encoding of the `identifiants` body (`authentication_encoding`); checked when the gateway is built.
     */
    public function withAuthenticationEncoding(string $encoding): self
    {
        return new self(
            $this->endpoints,
            $encoding,
            $this->depositContentType,
            $this->maxFlowBytes,
            $this->maxResponseBytes,
            $this->returnsTimeZone,
            $this->declarationMode,
        );
    }

    /**
     * `Content-Type` of the deposit request (`deposit_content_type`): the PDF example says `text/plain`, the Swagger none.
     *
     * @throws InvalidValue `deposit_content_type.invalid_format`
     */
    public function withDepositContentType(string $contentType): self
    {
        if (preg_match('/^[\x21-\x7E]+(?: [\x21-\x7E]+)*$/D', $contentType) !== 1) {
            throw InvalidValue::invalidFormat('deposit_content_type', 'a media type without control characters');
        }

        return new self(
            $this->endpoints,
            $this->authenticationEncoding,
            $contentType,
            $this->maxFlowBytes,
            $this->maxResponseBytes,
            $this->returnsTimeZone,
            $this->declarationMode,
        );
    }

    /**
     * Local limit of the uncompressed flow (`max_flow_bytes`), null to disable it.
     *
     * @throws InvalidValue `max_flow_bytes.not_positive`
     */
    public function withMaxFlowBytes(?int $bytes): self
    {
        if ($bytes !== null && $bytes < 1) {
            throw InvalidValue::notPositive('max_flow_bytes');
        }

        return new self(
            $this->endpoints,
            $this->authenticationEncoding,
            $this->depositContentType,
            $bytes,
            $this->maxResponseBytes,
            $this->returnsTimeZone,
            $this->declarationMode,
        );
    }

    /**
     * Limit of a decoded answer (`max_response_bytes`, against decompression bombs), null to disable it.
     *
     * @throws InvalidValue `max_response_bytes.not_positive`
     */
    public function withMaxResponseBytes(?int $bytes): self
    {
        if ($bytes !== null && $bytes < 1) {
            throw InvalidValue::notPositive('max_response_bytes');
        }

        return new self(
            $this->endpoints,
            $this->authenticationEncoding,
            $this->depositContentType,
            $this->maxFlowBytes,
            $bytes,
            $this->returnsTimeZone,
            $this->declarationMode,
        );
    }

    /**
     * Time zone of the dates of the list paths and of `publication` / `production` (`returns_time_zone`).
     */
    public function withReturnsTimeZone(DateTimeZone $timeZone): self
    {
        return new self(
            $this->endpoints,
            $this->authenticationEncoding,
            $this->depositContentType,
            $this->maxFlowBytes,
            $this->maxResponseBytes,
            $timeZone,
            $this->declarationMode,
        );
    }

    /**
     * The highest mode this installation may deposit (`declaration_mode`, ADR 0003): `Test` (the default) refuses every
     * `Real` flow before any call, `Real` allows both. Never `Real` by default.
     */
    public function withDeclarationMode(DeclarationMode $mode): self
    {
        return new self(
            $this->endpoints,
            $this->authenticationEncoding,
            $this->depositContentType,
            $this->maxFlowBytes,
            $this->maxResponseBytes,
            $this->returnsTimeZone,
            $mode,
        );
    }

    public function declarationMode(): DeclarationMode
    {
        return $this->declarationMode;
    }

    /**
     * Local checks of a flow before it is deposited, made before any call: the mode of the flow must be allowed by the
     * ceiling of the installation ({@see DeclarationMode::allows()}), then the size limit.
     *
     * @throws InvariantViolation `declaration_mode.not_allowed`
     * @throws InvalidValue       `flow.too_large`
     */
    public function assertDepositable(DpaeFlow $flow): void
    {
        if (!$this->declarationMode->allows($flow->mode())) {
            throw InvariantViolation::because(
                'declaration_mode.not_allowed',
                'A real declaration cannot be deposited by an installation capped at the test mode.',
                ['mode' => $flow->mode()->value, 'installation_mode' => $this->declarationMode->value],
            );
        }

        if ($this->maxFlowBytes !== null && \strlen($flow->bytes()) > $this->maxFlowBytes) {
            throw InvalidValue::tooLarge('flow');
        }
    }

    public function endpoints(): ServiceEndpoints
    {
        return $this->endpoints;
    }

    public function authenticationEncoding(): string
    {
        return $this->authenticationEncoding;
    }

    public function depositContentType(): string
    {
        return $this->depositContentType;
    }

    public function maxFlowBytes(): ?int
    {
        return $this->maxFlowBytes;
    }

    public function maxResponseBytes(): ?int
    {
        return $this->maxResponseBytes;
    }

    public function returnsTimeZone(): DateTimeZone
    {
        return $this->returnsTimeZone;
    }
}
