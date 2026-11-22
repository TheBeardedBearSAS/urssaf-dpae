# ADR 0005 — `DepositResult` and unknown deposit outcome

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0003](0003-declaration-mode-and-simulator-guard.md),
  [ADR 0004](0004-xml-gzip-client.md), [ADR 0006](0006-polling-constraints.md),
  [ADR 0008](0008-doctrine-persistence.md)

## Context

- "Le retour de l'AEE ou de l'avis de rejet est synchrone": the deposit answers 200 with the AEE or 422 with the
  rejection notice ("Avis de rejet"), both as `envoi` XML ([api-reference.md](../api-reference.md) §2.2). An AEE and a
  rejection notice are mutually exclusive for one flow (§2.2, NAT/5491).
- Minimum content of both (NAT/5491): `IdFluxDPAE` (set by the deposit system), flow name, archive name if any,
  reference of the identified declarant (the *triplet* name, first name, SIRET), generation date; the rejection notice
  adds the reject reasons (§2.2). The XML schemas are not provided and no example is published (§5.9). The Swagger
  `envoi` gives `envoi_identification.declarant` (`siret`, `nom`, `prenom`) and `envoi_bilan.envoi_etat` typed as
  `retours` (§2.2).
- The deposit has no idempotency key. There is no "annule et remplace"; a duplicate is detected only by the business
  control (code `98`, §1.2, §3.5) after it has been recorded.
- The AEE / rejection notice can be "téléchargé de nouveau" through the return services: it is the return of nature
  `10` (§2.2, §3.1). The lists return, per `flux.id`, the `retour` entries with `nature`, `statut`, `publication`,
  `production`, `id`, `url` (§2.3) — **no SIRET and no content-derived value**.
- `publication` is "l'horodatage de son enregistrement sur le SI DPAE"; its time zone is not published (§2.3, §5.8).
- For a concentrator, the concentrator SIRET authenticates and the declarant SIRET is used in the flows (§2.1, FAQ).
  Which SIRET the AEE triplet carries for a concentrator is not published (§5.9).
- The reference never resends a write that may have reached URSSAF; `urssaf-plateforme-numerique` (its ADR 0003
  "Unknown upload outcome") reconciles from search results.

## Decision

### Result sum type

`Domain\Deposit\DepositResult = ElectronicReceipt|RejectionNotice`, two `final readonly` classes:

- `ElectronicReceipt` (HTTP 200): `?FlowId $flowId` (`IdFluxDPAE`, 1–50), `?Siret $declarantSiret`,
  `?DateTimeImmutable $generatedAt`, `RawDocument $document` (the answer bytes and `Content-Type`, kept verbatim).
- `RejectionNotice` (HTTP 422 with an XML body): the same four fields plus `list<RawRejectReason> $reasons` (raw
  code / label pairs as found, possibly empty).
- Extraction follows the Swagger `envoi` names ([ADR 0004](0004-xml-gzip-client.md) tolerant readers). The fields are
  nullable because the wire form is not published (§5.9): an AEE whose `IdFluxDPAE` cannot be located is still a
  receipt (URSSAF answered 200), and its flow id is then resolved by the reconciliation below.

### Deposit lifecycle

`Domain\Deposit\Deposit` aggregate, states (`DepositState`):

```
pending ──claim──▶ depositing ──200──────────────▶ received
   ▲                   │       ──422 (XML)───────▶ rejected
   │                   │       ──400─────────────▶ failed
   │                   │       ──406/415/404/3xx─▶ failed
   │                   │       ──credential 401──▶ invalid_credentials ──requeue──▶ pending
   └──── not sent ─────┤
                       └──── unknown ─────────────▶ outcome_unknown ──adopt──▶ received | rejected
                                                                   └─resolve──▶ received | rejected | not_received
```

- Not sent (back to `pending`, attempt counted, retried later up to `WorkflowPolicy::maxDepositAttempts()`, then
  `failed`): the token call failed (transport, 5xx, 422), a 401 `Jeton manquant ou invalide`, a second 401
  `Expiration du jeton` after the replay, or a local refusal before the request was sent. Each of these proves the
  flow was not processed (a 401 is answered before processing, [ADR 0001](0001-xml-token-authentication.md)).
- Credential refusals (`PasswordExpired`, `InvalidCredentials`, `NotRegistered`) go to `invalid_credentials`.

- Unknown outcome (`Application\Port\Urssaf\OutcomeUnknown`, `mayHaveReachedUrssaf()` true): transport error or
  timeout after the request was started, any 5xx, a 200 that is not a readable XML document (HTML, empty, malformed),
  a 422 whose body is not XML (an Apache page instead of the notice, §1.3), and any non-package `Throwable` during the
  send. A deposit left `depositing` longer than `WorkflowPolicy::staleDepositAfter()` (crash between claim and answer)
  is moved to `outcome_unknown` by a conditional claim, as in PN.
- 400 "Requête incorrecte ou erreur fonctionnelle lors de la récupération" and 406 / 415 / 404 / 3xx are read as "not
  processed" (`failed`); they are not retried automatically either. The 400 payload is undocumented (§5.14).
- A 401 replay is safe (the call was not processed, [ADR 0001](0001-xml-token-authentication.md)).

### Idempotence and the flow fingerprint

- `DepositDpae` carries the host's `ExternalReference` (its identifier of the hire), as the reference's use cases do
  (ADR 0000 §9).
- `FlowFingerprint`: SHA-256 of the **uncompressed** flow bytes as deposited (hex). It is a **local** key only:
  nothing published echoes it (§2.2, §2.3).
- A deposit is **open** until it ends in a state where URSSAF recorded nothing: `failed`, `rejected` (a rejection
  notice records no DPAE) and `not_received` close it. `received`, and every in-flight state, keep it open.
- Both `(tenant, external reference)` and `(tenant, fingerprint)` are unique among the open deposits
  ([ADR 0008](0008-doctrine-persistence.md)). `DepositDpae` called again with the reference of an open deposit
  returns it (if the fingerprint differs: `InvariantViolation` `deposit.reference_conflict`); a flow whose fingerprint
  belongs to an open deposit under another reference is refused (`deposit.flow_already_open`). Once closed, the same
  reference and flow may be deposited again: that is the host's explicit resend.
- An atomic claim (`pending` → `depositing`) precedes the HTTP call; the attempt counter is capped
  (`WorkflowPolicy::maxDepositAttempts()`); a deposit is **never resent blindly** once it may have reached URSSAF.

### Reconciliation key

The key that ties a lost answer to a flux published by URSSAF is **declarant SIRET + flow fingerprint + publication
window**, each component compared with what URSSAF actually returns:

| Component | Local side | Remote side | Role |
|---|---|---|---|
| Flow fingerprint | `Deposit::fingerprint()` | none (not published) | Identifies the local deposit to reconcile (one open deposit per fingerprint and tenant); shown to the operator as evidence. Never matched remotely. |
| Publication window | `[claimedAt − reconciliationSkew, claimedAt + reconciliationWindow]`, both `WorkflowPolicy` tunables | `retour.publication` of the nature `10` return, read in the configured time zone ([ADR 0006](0006-polling-constraints.md)) | Selects the candidate flux. |
| Declarant SIRET | `Deposit::declarantSiret()`: the tenant's SIRET for a direct token (`25`), the employer SIRET read from the flow (`rxorg:FR_Organization.SIRET.Identifier`) for a concentrator token (`98`) | `envoi_identification.declarant.siret` of the downloaded nature `10` document | Filters the candidates, when the document can be read. |

`ReconcileUnknownDeposits` (per tenant) handles the deposits **without a known flow id**: `outcome_unknown` ones, and
`received` / `rejected` ones whose answer carried no readable `IdFluxDPAE`. It:

1. lists the returns over the widest window of those deposits (one list call per run, two-date path,
   [ADR 0006](0006-polling-constraints.md));
2. takes as candidates the `flux.id` with a nature `10` return published in a deposit's window, **not already linked
   to a local deposit** of the tenant;
3. downloads each candidate's nature `10` document (stored, [ADR 0008](0008-doctrine-persistence.md)) and discards a
   candidate whose declarant SIRET is readable and differs from the deposit's;
4. lets a deposit **adopt** a candidate only if exactly one candidate remains for it **and** no other deposit of the
   tenant without a known flow id — in any state, `depositing` included — has a window containing that candidate's
   publication (restricted to the same declarant SIRET when the candidate's SIRET was read). The deposit then takes
   the `flux.id`. An `outcome_unknown` deposit also moves to `received` (document read as an AEE, or `statut` `OK`)
   or `rejected` (document read as a rejection notice, or `statut` `KO`); any other status keeps it
   `outcome_unknown` for the next run. The `statut` reading (`OK` = AEE, `KO` = rejection notice) is inferred
   (§5.9). A `received` / `rejected` deposit keeps its state and only gains the flow id;
5. leaves the others as they are; past `WorkflowPolicy::manualReviewAfter()` they are reported as needing manual
   review, with external reference, fingerprint, claim instant and candidate flux ids.

`ResolveDepositOutcome` lets an operator record `received(FlowId)`, `rejected(FlowId)` or `not_received`; only once
the deposit is closed (`rejected`, `not_received`) may the host deposit the same flow again. Defaults of the three tunables are package defaults, not
specification values, and are listed in §5.8.

## Consequences

- The package never sends a DPAE twice by itself; a host resend after `not_received` that turns out to be a duplicate
  is caught by URSSAF (code `98`, §3.5).
- While the AEE schema is unknown, most reconciliations will end in manual review for concentrators with many
  declarants; contract-test samples (§5.9) make them automatic. The candidate downloads also fill the stored returns.
- The adopted flux id and the deposit's returns join through `flux.id` ([ADR 0006](0006-polling-constraints.md),
  [ADR 0008](0008-doctrine-persistence.md)).
- This ADR defines the reconciliation key that ADR 0000 left open; it cannot be `IdFluxDPAE`, which the lost answer
  carried.

## Amendment 2026-11-22 — shape of the result types

Refines "Result sum type" as implemented in lot 1.

- `DepositResult` is an interface, sealed **by convention** only (PHP 8.2 has no sealed types): `flowId(): ?FlowId`,
  `declarantSiret(): ?Siret`, `generatedAt(): ?Instant`, `document(): RawDocument`.
- `ElectronicReceipt::of(?FlowId, ?Siret, ?Instant, RawDocument)`; `RejectionNotice::of(?FlowId, ?Siret, ?Instant,
  RawDocument, list<string> $reasons)`. The generation date is a `Shared\Instant` (UTC, second precision), not a
  `DateTimeImmutable`; the reasons are the raw strings found in the notice (possibly none), not code / label pairs.
- `Deposit\RawDocument`: the answer bytes (non-blank) and the raw `Content-Type` value, kept verbatim.
- Privacy: `__debugInfo()` of `RawDocument`, `ElectronicReceipt`, `RejectionNotice` (and `ReturnContent`) shows the size
  and SHA-256 of the content, never the content; a rejection notice shows only the number of reasons. These objects, as
  `DpaeFlow`, cannot be serialized (`Domain\Exception\PayloadNotSerializable`, a `LogicException` implementing
  `UrssafException`).
- `Deposit\FlowFingerprint` lives in the `Deposit` namespace (not `Declaration`).

## Amendment 2026-11-22 — how `EnvoiXmlReader` reads the answer (lot 3)

- **Reject reasons are `list<string>`**, the raw strings found in the notice, as implemented in
  `RejectionNotice::of(…, list<string> $reasons)`; the `list<RawRejectReason>` of "Result sum type" is superseded
  (the shape amendment above already said so; this one aligns the body).
- The XML form of `envoi` is not published, so these names are **assumed**, not attested: flow id `IdFluxDPAE` (else the
  `id` of the first `flux`, Swagger), generation date `DateGeneration` / `dateGeneration`, reasons `motif` / `motifRejet` /
  `MotifRejet`, each as an element or an attribute. They are the defaults of `EnvoiVocabulary`, which a host overrides
  (`EnvoiXmlReader::receiptWith()` / `rejectionWith()`) once the real names are known. The `publication` of a `retour`
  is **not** a fallback for the generation date (it is another instant).
- The declarant SIRET is looked up under `declarant` first (Swagger `envoi_identification.declarant.siret`), then
  anywhere in the document. Every field is nullable and an unusable value is null, never an error; only an unreadable
  body (not XML, DOCTYPE, unsupported charset) is `UnexpectedUrssafResponse`. The raw answer is kept as the `RawDocument`.

## Amendment 2026-11-22 — `OutcomeUnknown` and the deposit boundary (lot 4)

- `Application\Port\Urssaf\OutcomeUnknown` (not a `RetryableException`, `mayHaveReachedUrssaf()` always true) has three
  factories: `afterFailure(Operation, class)` (transport error, timeout or anything the client throws once the request
  was started; only the class name is kept), `afterStatus(Operation, status)` (5xx) and `unreadableAnswer(Operation,
  reason, ?previous)` (HTML, blank, malformed, DOCTYPE, bad gzip, over the response limit, unsupported charset, a 422
  that is not XML).
- The token is obtained **before** the unknown-outcome boundary: a failed first token call or re-authentication is an
  `UrssafUnavailable` (`mayHaveReachedUrssaf()` false), a credential refusal an `UrssafAuthenticationFailed`; neither is
  an unknown outcome.
- Any 2xx is read as the receipt (the contract says 200), a 429 is a `PollingConstraintViolated` (not processed), and
  `max_flow_bytes` is checked before any call (`InvalidValue` `flow.too_large`).

## Amendment 2026-11-22 — any other failure after the send

`UrssafHttpGateway::deposit()` turns every `Throwable` that is neither an `UrssafException` nor a `RetryableException`
(a bug, a failing collaborator) into `OutcomeUnknown::afterFailure()` once the call may have left, keeping only its class
name: it must never surface as a failure that lets the caller resend the flow.

## Amendment 2026-11-22 — the deposit use cases as implemented (lot 5)

- **State name.** The `outcome_unknown` state keeps its ADR value (`DepositState::OutcomeUnknown`).
- **Identity.** A deposit has a local `DepositId` (generated by `IdGenerator`), because the same reference may be
  deposited again once the previous deposit is closed. `Deposit::claimedAt()` is never null: it is the creation instant
  until the first claim (it only means something once `attempts()` is positive).
- **Retry of a `pending` deposit.** The flow is not stored (ADR 0008): the host sends a `pending` deposit again by calling
  `DepositDpae` with the same reference and flow. The claim (`DepositRepository::claim()`, a conditional
  `pending` → `depositing`) precedes every send; a worker that loses the claim never calls the gateway.
- **Declarant SIRET.** `Deposit::declarantSiret()` is the tenant's SIRET for a direct identity (service `25`) and **null for
  a concentrator**: the employer SIRET of the flow is not read in lot 5.
- **Failure ladder** (`Application\Service\SendFailure`): only a failure that proves nothing was processed puts the deposit
  back to `pending` (or `failed` at `maxDepositAttempts`) — `UrssafUnavailable` without `mayHaveReachedUrssaf()`, a
  non-credential `UrssafAuthenticationFailed`, `PollingConstraintViolated`, any `DomainException` of a local refusal — and
  it is rethrown. `OutcomeUnknown`, `UrssafUnavailable::mayHaveReachedUrssaf()` and any non-package `Throwable` are an
  unknown outcome (the latter rethrown). A credential refusal is `invalid_credentials`, and every other
  `UrssafException` (`UrssafRequestRejected`, `UnexpectedUrssafResponse` for 404 / 406 / 415 / 3xx) is `failed`.
- **Reconciliation deviation.** `ReconcileUnknownDepositsHandler` does **not** download the nature `10` document: the
  declarant SIRET is not compared (step 3 of "Reconciliation key" is not implemented), because the `envoi` schema is
  unpublished and the Application layer has no reader port for it (§5.9). The other rules are implemented as written:
  sole candidate in `[claimedAt − skew, claimedAt + window]` (both bounds included), no other deposit without a flow id
  (`depositing` ones included) with a window containing it, `OK` → `received`, `KO` → `rejected`, any other status keeps the
  deposit unknown, `received` / `rejected` deposits only gain the flow id, and an unknown outcome older than
  `manualReviewAfter` is reported (external reference, fingerprint, claim instant, candidate flux ids). A lone flux
  published by another channel in the window would therefore be adopted: listed in api-reference.md §5.23. A `depositing`
  deposit older than `staleDepositAfter` is first moved to `outcome_unknown` (`deposit.stale`).
- `ResolveDepositOutcome` and `RequeueInvalidCredentials` are not shipped yet; their domain transitions
  (`Deposit::resolveAsReceived` / `resolveAsRejected` / `resolveAsNotReceived`, `requeue`) are.

## Amendment 2026-11-22 — concurrency, adoption and the failure ladder (lot 5 review)

Supersedes the parts of the lot 5 amendment it contradicts.

- **Optimistic version.** `Deposit::version()` is the version the aggregate was read at. `DepositRepository::save()` refuses
  (`ConcurrentModification`, code `deposit.concurrent_modification`, a `RetryableException`) a deposit whose version is not the stored
  one, and moves it on (`Deposit::advanceVersion()`); a conditional `claim()` moves the stored version too
  ([ADR 0008](0008-doctrine-persistence.md) for the contract a Doctrine adapter implements). The deposit handler's
  updates, the sweep and the adoption go through `DepositUpdater`: each attempt is its own unit of work, a lost race reloads
  the deposit and lets the change decide again, three attempts at most. A **late answer** (the send returned after a sweep
  moved the deposit to `outcome_unknown`) therefore settles the unknown outcome: `Deposit::recordResult()` accepts
  `depositing` and `outcome_unknown`; a failure that arrives after the sweep leaves it unknown.
- **Failure ladder.** A `DomainException` is `NotSent` only for the two codes the package raises before anything leaves,
  `flow.too_large` and `declaration_mode.not_allowed`; raised after the send it is an unknown outcome (not rethrown). The
  contract is written in the docblock of `DpaeGateway::deposit()`. Replaces "any `DomainException` of a local refusal".
- **Attempts.** `Deposit::startDeposit($now, $maxAttempts)` refuses a claim without attempt left
  (`deposit.attempts_exhausted`); `DepositRepository::claim()` returns false then. `Deposit::requeue()` sets the counter back to
  zero: a credential refusal says nothing about the flow.
- **Events.** `urssaf.dpae.deposit.failed` (a deposit closed without a receipt: attempts exhausted, request refused),
  `urssaf.dpae.deposit.parked` (`invalid_credentials`) and `urssaf.dpae.deposit.adopted` (see below) join the catalogue.
- **Adoption is an inference and says so.** `Deposit::adoptedByReconciliation()` and the `DepositAdopted` event (public
  `urssaf.dpae.deposit.adopted`: flux id and resulting state) mark a flow id found by the reconciliation, in addition to
  `DepositReceived` / `DepositRejected` for an unknown outcome. `ReconciliationReport::$adopted` lists exactly these.
- **Adoption conditions, added.** A deposit adopts only once `now` is after the end of its window (a later flux can no longer
  compete), and only if `WorkflowPolicy::autoAdopt($deposit)` allows it: by default `true` for a `test` deposit of a direct
  declarant, `false` for a `real` deposit and for a deposit without declarant SIRET (a concentrator); the tunable forces
  either answer. A sole candidate the policy refuses is reported (`ManualReviewItem::ADOPTION_NOT_AUTOMATIC`) and left alone.
- **Listing range.** The reconciliation lists `[max(start, now − 13 months), min(end, now)]`, cut into ranges of at most
  `pollingMaxRange` like `SynchronizeReturns`. A deposit whose window ended more than 13 months ago cannot be found any more:
  it is reported for manual review (`OUT_OF_RETENTION`) without being listed. A polling refusal (422 / 429) is reported
  (`ReconciliationReport::$refused`, `$retryAfterSeconds`), not thrown: nothing is adopted.
- **Unattached flux.** The nature `10` flux that no local deposit holds and no read deposit's window contains (another
  channel, or a deposit this package did not record) are listed in `ReconciliationReport::$unattachedFlux` for a human; they
  are never adopted.
- **Batches.** `withoutFlowId()` and `staleDepositing()` take a limit (`WorkflowPolicy::RECONCILIATION_BATCH`, the oldest claims
  first). When a batch is full, a deposit is only adopted if no deposit left unread can compete for its flux (the last window read
  starts after its window ends).
- `ExternalReference` carries no personal data (no NIR, no name): it is stored, relayed in events and logged.
