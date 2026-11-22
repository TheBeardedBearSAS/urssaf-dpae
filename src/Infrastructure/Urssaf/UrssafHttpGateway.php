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

use TheBeardedBear\UrssafDpae\Application\Port\CredentialsProvider;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\DpaeGateway;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\ReturnNoLongerAvailable;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvalidValue;
use TheBeardedBear\UrssafDpae\Domain\Exception\InvariantViolation;
use TheBeardedBear\UrssafDpae\Domain\Exception\UrssafException;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnContent;
use TheBeardedBear\UrssafDpae\Domain\Return\ReturnReference;
use TheBeardedBear\UrssafDpae\Domain\Shared\Instant;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\AnswerInterpreter;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\ApiRequest;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Http\XmlApiClient;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\RetoursXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;
use Throwable;

/**
 * HTTP implementation of {@see DpaeGateway}: deposit, return lists and return downloads. Build it with
 * {@see UrssafGatewayFactory}.
 *
 * Statuses are mapped as in ADR 0004: the body of an error answer is never read, except the deposit 422 which is the
 * rejection notice. The deposit is never resent and never reports "not received" on a doubt: transport errors, 5xx and
 * unreadable answers are an {@see OutcomeUnknown} (ADR 0005).
 */
final readonly class UrssafHttpGateway implements DpaeGateway
{
    public function __construct(
        private CredentialsProvider $credentialsProvider,
        private XmlApiClient $client,
        private ApiConfiguration $configuration,
        private AnswerInterpreter $answers,
    ) {}

    public function deposit(TenantId $tenantId, DpaeFlow $flow): DepositResult
    {
        $this->configuration->assertDepositable($flow);
        $bytes = $flow->bytes();

        $credentials = $this->credentialsProvider->forTenant($tenantId);
        $request = ApiRequest::postGzip(
            Operation::Deposit,
            $this->configuration->endpoints()->uri(DpaeService::Deposit, $credentials->service(), UrssafEndpoints::DEPOSIT_PATH),
            $bytes,
            $this->configuration->depositContentType(),
        );

        try {
            $answer = $this->client->send($tenantId, $credentials, $request);

            return $this->answers->depositResult($answer, WireDate::inZone($this->configuration->returnsTimeZone()));
        } catch (UrssafUnavailable $unavailable) {
            // A transport error or a timeout once the request was started: the flow may have been received. A failed
            // token call proves nothing was sent, and stays a retryable UrssafUnavailable.
            throw $unavailable->mayHaveReachedUrssaf()
                ? OutcomeUnknown::afterFailure(Operation::Deposit, $unavailable->causeClass() ?? $unavailable::class)
                : $unavailable;
        } catch (UrssafException $exception) {
            throw $exception;
        } catch (Throwable $failure) {
            // Anything else once the request may have left (a bug, a failing collaborator): never a failure that
            // would let the caller resend the flow. Only the class is kept, as for a transport error.
            throw OutcomeUnknown::afterCause(Operation::Deposit, $failure);
        }
    }

    /**
     * @return list<ReturnReference>
     */
    public function listReturns(TenantId $tenantId, Instant $from, ?Instant $to): array
    {
        if ($to instanceof Instant && $from->isAfter($to)) {
            throw InvariantViolation::because('returns.range_inverted', 'The start of the range is after its end.');
        }

        $credentials = $this->credentialsProvider->forTenant($tenantId);
        $dates = WireDate::inZone($this->configuration->returnsTimeZone());
        $path = UrssafEndpoints::RETURNS_LIST_PATH . '/' . $dates->format($from);
        if ($to instanceof Instant) {
            $path .= '/' . $dates->format($to);
        }

        $answer = $this->client->send($tenantId, $credentials, ApiRequest::get(
            Operation::ListReturns,
            $this->configuration->endpoints()->uri(DpaeService::Consultation, $credentials->service(), $path),
        ));
        if ($answer->status() === 422) {
            throw $this->answers->pollingConstraint($answer);
        }
        $this->answers->assertReadable(Operation::ListReturns, $answer);

        return RetoursXmlReader::read($answer->body(), $answer->contentType(), $dates);
    }

    public function downloadReturn(TenantId $tenantId, ReturnReference $return): ReturnContent
    {
        $credentials = $this->credentialsProvider->forTenant($tenantId);
        $answer = $this->client->send($tenantId, $credentials, ApiRequest::get(
            Operation::DownloadReturn,
            ReturnDownloadUri::of($this->configuration->endpoints(), $return, $credentials->service()),
        ));

        $status = $answer->status();
        if ($status === 403) {
            throw UrssafRequestRejected::because(Operation::DownloadReturn, $status, 'return.not_owned');
        }
        if ($status === 404) {
            throw ReturnNoLongerAvailable::forReturn($return);
        }
        if ($status === 422) {
            throw UrssafRequestRejected::because(Operation::DownloadReturn, $status, 'return.invalid_parameters');
        }
        $this->answers->assertReadable(Operation::DownloadReturn, $answer);

        try {
            return ReturnContent::of($answer->body(), $answer->contentType());
        } catch (InvalidValue $exception) {
            throw UnexpectedUrssafResponse::causedBy('the downloaded document is empty', $exception);
        }
    }
}
