<?php

declare(strict_types=1);

/*
 * This file is part of thebeardedbear/urssaf-dpae.
 *
 * Copyright (c) TheBeardedBear and the urssaf-dpae contributors
 *
 * SPDX-License-Identifier: MIT
 */

namespace TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TheBeardedBear\UrssafDpae\Domain\Exception\AuthenticationFailure;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use Throwable;

/**
 * What the gateway reads of an HTTP answer (ADR 0004): the status, the headers it needs, and the body, read and decoded
 * only when asked for. The body of an error answer is therefore never read, and a non-gzip or oversized body is
 * refused only by whoever needs it.
 *
 * @internal
 */
final readonly class HttpAnswer
{
    /** `realm=` in a `WWW-Authenticate` value: between quotes, or between guillemets (UTF-8 or ISO-8859-1). */
    private const REALM = '/realm\s*=\s*(?|"([^"]*)"|\xC2\xAB(.*?)\xC2\xBB|\xAB(.*?)\xBB)/i';
    private const MAX_REALM_LENGTH = 100;
    private const READ_CHUNK_BYTES = 8192;
    /** Slack of a compressed body over the decoded limit (gzip header, and stored blocks of incompressible data). */
    private const GZIP_OVERHEAD_BYTES = 1024;
    /** Plus one byte per this many decoded bytes (stored blocks cost 5 bytes per 65535). */
    private const GZIP_OVERHEAD_DIVISOR = 1024;

    private function __construct(
        private int $status,
        private StreamInterface $stream,
        private string $contentType,
        private string $contentEncoding,
        private string $authenticate,
        private string $retryAfter,
        private ?int $maxBodyBytes,
    ) {}

    /**
     * @param int|null $maxBodyBytes limit of the decoded body (`max_response_bytes`), null for none
     */
    public static function fromResponse(ResponseInterface $response, ?int $maxBodyBytes): self
    {
        return new self(
            $response->getStatusCode(),
            $response->getBody(),
            $response->getHeaderLine('Content-Type'),
            $response->getHeaderLine('Content-Encoding'),
            $response->getHeaderLine('WWW-Authenticate'),
            $response->getHeaderLine('Retry-After'),
            $maxBodyBytes,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * The raw `Content-Type` header, an empty string when there is none.
     */
    public function contentType(): string
    {
        return $this->contentType;
    }

    /**
     * True for an HTML page, what an Apache error page or a proxy answers instead of the XML of the API.
     */
    public function isHtml(): bool
    {
        return stripos($this->contentType, 'text/html') !== false;
    }

    /**
     * @param DateTimeImmutable $now reference of an HTTP-date `Retry-After`
     */
    public function retryAfterSeconds(DateTimeImmutable $now): ?int
    {
        return RetryAfter::seconds($this->retryAfter, $now);
    }

    /**
     * The 401 realm of `WWW-Authenticate` (ADR 0001); `Unknown` when there is none or it is not a published one.
     */
    public function authenticationFailure(): AuthenticationFailure
    {
        return AuthenticationFailure::fromRealm($this->realm());
    }

    /**
     * The raw realm of `WWW-Authenticate` (not personal data, ADR 0001), trimmed and cut to a short length; null when there is none.
     */
    public function realm(): ?string
    {
        $match = [];
        if (preg_match(self::REALM, $this->authenticate, $match) !== 1) {
            return null;
        }

        return substr(trim($match[1]), 0, self::MAX_REALM_LENGTH);
    }

    /**
     * The body, decoded when it is still gzip-compressed (the PSR-18 client did not do it).
     *
     * @throws UnexpectedUrssafResponse for an unreadable body, another content coding, an undecodable gzip body or a body over
     *                                  the limit
     */
    public function body(): string
    {
        return match (strtolower($this->contentEncoding)) {
            '', 'identity' => $this->read($this->maxBodyBytes),
            'gzip', 'x-gzip' => $this->gunzip($this->read($this->maxBodyBytes === null ? null : $this->maxBodyBytes + intdiv($this->maxBodyBytes, self::GZIP_OVERHEAD_DIVISOR) + self::GZIP_OVERHEAD_BYTES)),
            default => throw UnexpectedUrssafResponse::invalidPayload('the content coding of the body is not supported'),
        };
    }

    /**
     * Reads the stream by blocks, stopping at `$limit + 1` bytes so that an oversized body is never held whole.
     *
     * @throws UnexpectedUrssafResponse for an unreadable body or one over `$limit`
     */
    private function read(?int $limit): string
    {
        $bytes = '';

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }

            while (!$this->stream->eof()) {
                $chunk = $this->stream->read($limit === null ? self::READ_CHUNK_BYTES : min(self::READ_CHUNK_BYTES, $limit + 1 - \strlen($bytes)));
                if ($chunk === '') {
                    break;
                }

                $bytes .= $chunk;
                if ($limit !== null && \strlen($bytes) > $limit) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            throw UnexpectedUrssafResponse::causedBy('the body cannot be read', $exception);
        }

        if ($limit !== null && \strlen($bytes) > $limit) {
            throw UnexpectedUrssafResponse::invalidPayload('the body exceeds the size limit');
        }

        return $bytes;
    }

    private function gunzip(string $bytes): string
    {
        // gzdecode() warns on corrupt data and on a decoded size over its limit; both are the same refusal.
        set_error_handler(static fn(): bool => true);
        $decoded = gzdecode($bytes, $this->maxBodyBytes ?? 0);
        restore_error_handler();

        if ($decoded === false) {
            throw UnexpectedUrssafResponse::invalidPayload('the gzip body cannot be decoded within the size limit');
        }

        return $decoded;
    }
}
