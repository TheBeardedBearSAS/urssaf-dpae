# Workflow

How a deposit and a return move through the package: the two state machines, idempotence, retry, reconciliation, the
outbox and the polling. The decisions are in [ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md) and
[ADR 0006](adr/0006-polling-constraints.md); the specification is in [api-reference.md](api-reference.md). The one rule
behind everything: **a flow that may have reached URSSAF is never sent a second time.**

## Deposit state machine

`Domain\Deposit\Deposit` (an aggregate, one per `(tenant, external reference)` while it is open). The transitions are
methods of the aggregate and refuse any other origin state with an `InvalidStateTransition`.

```
                         claim (atomic)                 receipt (200)
            +---------+ ----------------> +------------+ ------------------> received
  record -> | pending |                   | depositing |  rejection (422)  -> rejected
            +---------+ <---------------- +------------+
                 ^       not sent, attempts   |  |  |  400, 404, 406, 415, 3xx -> failed
                 |       left (markNotSent)   |  |  +--- not sent, no attempt left -> failed
                 |                            |  +------ 401 credential refusal -> invalid_credentials
                 |                            +--------- transport error, timeout, 5xx,
                 |                                       unreadable 200 / 422 -> outcome_unknown
                 +--- requeue (attempts reset) --- invalid_credentials

  outcome_unknown --- reconciliation or resolution --> received | rejected | not_received
  outcome_unknown --- late answer of URSSAF (the sweep was too early) --> received | rejected
```

| State | Open | Meaning | Leaves through |
|---|---|---|---|
| `pending` | yes | Recorded, not sent (or provably not sent: the token call failed, throttling, a local refusal) | the claim |
| `depositing` | yes | One worker owns the send | an answer; the stale sweep if the worker died |
| `received` | yes | Electronic receipt (AEE) read | final (the flow id may be adopted later) |
| `rejected` | no | Rejection notice (422 with an XML body), URSSAF recorded nothing | final, the same reference may be deposited again |
| `failed` | no | 400 and other answers that prove the flow was not processed, or `maxDepositAttempts` reached | final, may be deposited again |
| `outcome_unknown` | yes | The flow may have reached URSSAF and the answer is lost or unreadable | the reconciliation, or a late answer (`received` / `rejected`) |
| `not_received` | no | Settled by a human: URSSAF never got it | final, may be deposited again |
| `invalid_credentials` | yes | The credentials were refused: parked, nothing is lost | `requeue` once the credentials are fixed |

Only `failed`, `rejected` and `not_received` close a deposit (`DepositState::isOpen()`).

A `Deposit` also carries a monotonic `version` (optimistic lock), the number of `attempts`, the instant of the last claim
(`claimedAt`, which anchors the reconciliation window), the last error code and, once known, the `FlowId` and the answer.
Its domain events are released after the transaction: `DepositReceived`, `DepositRejected`, `DepositFailed`,
`DepositOutcomeUnknown`, `DepositParked`, `DepositAdopted`.

## Return state machine

`Domain\Return\TrackedReturn` is one return of the listing, identified by `(tenant, flux.id, nature, ops)` (an absent
`ops` is the empty string). Its only state is the one of its document, `DownloadState`:

```
  (discovered)  entry event: the return is seen in a listing, it is saved straight in `pending`
                or `not_downloadable`; `discovered` is not a stored state.

  pending --- download 200 ----------------------------> downloaded
     |
     +----- 404 (purged after 3 months) ---------------> purged            (saved, then ReturnNoLongerAvailable)
     +----- no document, or url outside the configured
            download origin ---------------------------> not_downloadable (saved, then ReturnNotDownloadable)

  not_downloadable --- a later listing brings a document ----> pending
  not_downloadable --- download 200 -------------------------> downloaded
  not_downloadable --- 404 ----------------------------------> purged
```

A `downloaded` return is not overwritten by a stale synchronisation, and its content is served from the store without
a call. Returns and their documents are kept beyond the URSSAF purge; the package deletes nothing itself
([docs/doctrine.md](doctrine.md)). A return is linked to the deposit holding its flux id when there is one.

## Idempotence

`DepositDpae` is safe to call again with the same arguments, from a retried job or a double click:

1. the installation ceiling is checked first (`declaration_mode.not_allowed` for a `real` flow with a `test` ceiling);
2. the deposit is recorded **and committed** before any call: `(tenant, external reference)` and `(tenant, flow
   fingerprint)` (SHA-256 of the uncompressed flow) are unique among open deposits, so a repeated call returns the open
   deposit and sends nothing; the same reference with another flow is `deposit.reference_conflict`, the same flow under
   another reference `deposit.flow_already_open`;
3. the claim `pending` to `depositing` is one conditional statement: only the claimant calls the gateway; a loser gets
   the deposit as it stands (`depositing`);
4. a new deposit is possible only once the previous one is closed.

Saves are optimistic (`deposit.concurrent_modification`): the sweep, an adoption and a late answer reload the deposit and
decide again. The flow is **not** stored (personal data): a `pending` deposit is sent again by calling the handler with
the same reference and flow, or kept with `keep_flows` ([docs/doctrine.md](doctrine.md)).

## Retry

| Situation | Result | Retried |
|---|---|---|
| The token call fails, `Jeton manquant ou invalide`, a second `Expiration du jeton`, throttling (429), local refusal | `pending` again (`failed` at `maxDepositAttempts`, default 3) and the failure is **rethrown** | yes, by calling the handler again |
| A first `Expiration du jeton` | the token is dropped, the gateway authenticates again and replays the call once | automatic, once |
| 400, 404, 406, 415, a redirect | `failed`, returned | never automatically |
| 401 credential refusal (`PasswordExpired`, `InvalidCredentials`, `NotRegistered`) | `invalid_credentials`, returned | after `requeue` |
| Transport error after the send, timeout, 5xx, unreadable 200, non-XML 422 | `outcome_unknown`, returned | **never**: reconciliation |
| 422 with an XML body | `rejected` (rejection notice), returned | by a new deposit |
| Error the package does not classify (a bug, a failing collaborator) | `outcome_unknown` and the failure is **rethrown** | **never**: reconciliation |
| A `DomainException` raised after the send (a reader of the answer, a bug), other than `flow.too_large` and `declaration_mode.not_allowed` which are raised before anything leaves | `outcome_unknown` | **never**: reconciliation |

On the list and download calls a 422 or 429 is a polling refusal (`PollingConstraintViolated`, retryable, with the
`Retry-After` delay if any, else `pollingRetryDelaySeconds`).

## Reconciliation of an unknown outcome

`ReconcileUnknownDeposits`, from a cron, settles the deposits that have no flow id and never resends one:

1. a `depositing` deposit older than `staleDepositAfter` (a worker died between the claim and the answer) becomes
   `outcome_unknown`;
2. a deposit whose window is older than the 13 months URSSAF keeps goes to manual review;
3. the return list is read over `[claimedAt - reconciliationSkew, claimedAt + reconciliationWindow]` of the unsettled
   deposits, capped to the last 13 months and cut by `pollingMaxRange` like the synchronisation;
4. a candidate is a `flux.id` with a nature `10` return published in a deposit's window and not already attached to a
   local deposit;
5. a deposit adopts a candidate only once its window is closed, if it is the only one in its window **and** no other
   deposit without a flow id could claim the same flux, **and** `WorkflowPolicy::autoAdopt()` allows it (by default a
   `test` deposit of a known declarant, else manual review). Status `OK` gives `received`, `KO` gives `rejected`, any
   other status leaves the deposit unknown; an adoption is marked (`adoptedByReconciliation()`, `DepositAdopted`);
6. what is still unknown after `manualReviewAfter`, and every nature `10` flux that no deposit explains, is reported as a
   `ManualReviewItem` for a human.

The AEE is not downloaded to compare the declarant SIRET (the `envoi` schema is unpublished, api-reference.md section
5.23). At most 200 deposits are read per run, oldest claim first. A polling refusal is reported, not thrown: nothing is
adopted and the next run retries. A host may settle a deposit by hand with the domain transitions `resolveAsReceived`,
`resolveAsRejected` and `resolveAsNotReceived`.

## Outbox

Every state change runs in `UnitOfWork`: inside one transaction the aggregate is saved and its public integration events
(`Contract\Event\*`, names `urssaf.dpae.*`, versioned codec) are appended to the outbox; after the commit the domain events
are published in process. A failing listener is reported (`FailureReporter`) and never undoes the work. `RelayOutbox`
then delivers the pending entries to the `IntegrationEventSink` (Messenger by default) at least once: an entry is marked
dispatched only once the sink accepted it, a failure leaves it pending and holds back the later events of the same
deposit or return in that run, so the host receives them in order; consumers deduplicate on the event id. `PurgeOutbox`
removes the dispatched entries older than the retention period. Runs must not overlap: the Symfony bridge takes a lock
([docs/symfony.md](symfony.md)).

## Polling

`SynchronizeReturns` keeps one cursor per tenant. A run lists from the cursor (or `initialLookback`, default 7 days, for a
tenant never synchronised) to now, in ranges of at most `pollingMaxRange` when it is set, upserts the returns, and moves
the cursor to the instant the run **started** minus `pollingOverlap` (default 2 hours, wider than the Paris time shift)
only once the whole answer is stored. The overlap makes the next listing read again what was just published: the upsert
makes that harmless. A 422 / 429 stops the run, leaves the cursor and is reported with `retryAfterSeconds` and
`needsAttention` (after `maxPollingConstraintRetries`), so a batch over many tenants keeps going. Dates are
`yyyyMMddHHmmss` in `returns_time_zone` (default `Europe/Paris`); the maximum range and the quotas are not published
(api-reference.md sections 1.1 and 5.7).

The package does not poll by itself: call `SynchronizeReturns` and `ReconcileUnknownDeposits` from a cron, the
Symfony Scheduler schedule or a Messenger message ([docs/symfony.md](symfony.md)).
