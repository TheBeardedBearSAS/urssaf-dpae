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

use Psr\Clock\ClockInterface;
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\OutcomeUnknown;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\PollingConstraintViolated;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafRequestRejected;
use TheBeardedBear\UrssafDpae\Application\Port\Urssaf\UrssafUnavailable;
use TheBeardedBear\UrssafDpae\Domain\Deposit\DepositResult;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Exception\UnexpectedUrssafResponse;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\EnvoiXmlReader;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\Wire\WireDate;

/**
 * What the statuses mean for each operation (ADR 0004 to 0006). The body of an error answer is never read, except the
 * deposit 422 which is the rejection notice. The deposit never reports a failure on a doubt: transport errors, 5xx and
 * unreadable answers are an {@see OutcomeUnknown}.
 *
 * @internal
 */
final readonly class AnswerInterpreter
{
    public function __construct(private ClockInterface $clock) {}

    /**
     * 2xx is the electronic receipt, 422 the rejection notice.
     *
     * @throws UrssafRequestRejected     400 (`deposit.bad_request`): not processed
     * @throws PollingConstraintViolated 429
     * @throws OutcomeUnknown            5xx, or an answer that cannot be read as an `envoi`
     * @throws UnexpectedUrssafResponse  any other status (3xx, 404, 406, 415...): not processed
     */
    public function depositResult(HttpAnswer $answer, WireDate $dates): DepositResult
    {
        $status = $answer->status();

        if ($answer->isSuccess() || $status === 422) {
            return $this->readEnvoi($answer, $dates);
        }

        if ($status === 400) {
            throw UrssafRequestRejected::because(Operation::Deposit, $status, 'deposit.bad_request');
        }

        if ($status === 429) {
            throw $this->pollingConstraint($answer);
        }

        throw $status >= 500
            ? OutcomeUnknown::afterStatus(Operation::Deposit, $status)
            : UnexpectedUrssafResponse::unexpectedStatus(Operation::Deposit, $status);
    }

    /**
     * Statuses shared by the two reads: a 2xx must not be an HTML page, 429 is a polling constraint, 5xx is transient
     * (nothing was written), the rest is unexpected.
     *
     * @throws PollingConstraintViolated 429
     * @throws UrssafUnavailable         5xx
     * @throws UnexpectedUrssafResponse  an HTML page, or any other status
     */
    public function assertReadable(Operation $operation, HttpAnswer $answer): void
    {
        $status = $answer->status();

        if ($answer->isSuccess()) {
            if ($answer->isHtml()) {
                throw UnexpectedUrssafResponse::invalidPayload('the answer is an HTML page');
            }

            return;
        }

        if ($status === 429) {
            throw $this->pollingConstraint($answer);
        }

        throw $status >= 500
            ? UrssafUnavailable::withStatus($operation, $status, $answer->retryAfterSeconds($this->clock->now()))
            : UnexpectedUrssafResponse::unexpectedStatus($operation, $status);
    }

    /**
     * The refusal of a polling (422 on a list, 429 anywhere), with the delay URSSAF asked for, if any.
     */
    public function pollingConstraint(HttpAnswer $answer): PollingConstraintViolated
    {
        return PollingConstraintViolated::withStatus($answer->status(), $answer->retryAfterSeconds($this->clock->now()));
    }

    /**
     * Anything that cannot be read as an `envoi` leaves the outcome unknown: URSSAF may have recorded the flow.
     */
    private function readEnvoi(HttpAnswer $answer, WireDate $dates): DepositResult
    {
        if ($answer->isHtml()) {
            throw OutcomeUnknown::unreadableAnswer(Operation::Deposit, 'the answer is an HTML page', null);
        }

        try {
            return $answer->isSuccess()
                ? EnvoiXmlReader::receipt($answer->body(), $answer->contentType(), $dates)
                : EnvoiXmlReader::rejection($answer->body(), $answer->contentType(), $dates);
        } catch (UnexpectedUrssafResponse $exception) {
            throw OutcomeUnknown::unreadableAnswer(Operation::Deposit, 'the body is not a readable XML document', $exception);
        }
    }
}
