# ADR 0006 — Polling: 422 / 429 and return synchronisation

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0002](0002-hosts-per-service.md),
  [ADR 0004](0004-xml-gzip-client.md), [ADR 0005](0005-deposit-result-and-unknown-outcome.md),
  [ADR 0008](0008-doctrine-persistence.md)

## Context

- Two list paths: `/lister-retours-declarant/1.0/{dateDebut}` and `…/{dateDebut}/{dateFin}`, compared with the
  publication date of the returns, pattern `yyyyMMddHHmmss`; `dateFin` is declared required but described as optional,
  the optional form being the one-date path ([api-reference.md](../api-reference.md) §2.3, §2.4, §5.11).
- No numeric quota is published. HTTP 429 "Le client ne respecte pas les contraintes de polling (étendue de la plage
  de recherche)"; 422 "will be replaced by 429 lorsque la version d'Apache le permettra", so both can be received
  today; the maximum range is not published (§1.1, §5.7). On the same operations 422 is also described as "les
  paramètres sont invalides" (§2.3), so a 422 is ambiguous.
- No `Retry-After` is published.
- `publication` / `production` are written `yyyyMMddhhmmss` in the Swagger (lower-case `hh`); the time zone of the
  paths and fields is not published (§2.3, §5.8).
- `nature` "permet d'identifier de manière unique un retour, en provenance d'un même OPS destinataire" (§2.3).
- Follow-up data are available 13 months; downloadable returns 3 months, then purged (§1.2).
- The reference has per-operation quotas and a local rate limiter; they do not apply (ADR 0000 "Polling").

## Decision

- `DpaeGateway::listReturns(TenantId, DateTimeImmutable $from, ?DateTimeImmutable $to): ReturnList` uses the one-date
  path when `$to` is null and the two-date path otherwise. `$from <= $to` is the only local check; **no local range
  limit** is enforced by default.
- Path dates are formatted `YmdHis` (24-hour clock: `HH` is the pattern of the paths, and `20220130083000` the only
  example) after conversion to the configured time zone `returns_time_zone` (default `Europe/Paris`, §5.8).
  `publication` and `production` are read with the same 24-hour pattern in the same zone (the lower-case `hh` of the
  Swagger is read as a typo: a 12-hour reading without an AM/PM marker is ambiguous); the raw strings are kept next to
  the parsed instants, and an unparsable value keeps the return with a null instant.
- **422 and 429 on a list call both throw `Application\Port\Urssaf\PollingConstraintViolated`**, which implements
  `RetryableException`. Its delay is `Retry-After` when an answer carries one (seconds or HTTP date), otherwise
  `WorkflowPolicy::pollingRetryDelay()`. Its `status()` keeps 422 or 429, so that a persistent 422 — which may mean
  invalid parameters — is visible in the logs and metrics. After `WorkflowPolicy::maxPollingConstraintRetries()`
  consecutive refusals for a tenant, the synchronisation is reported as needing attention.
- `SynchronizeReturns` (per tenant, under a non-blocking lock, [ADR 0009](0009-coexistence.md)):
  - the cursor is a dedicated per-tenant row, moved **only** by `SynchronizeReturns` (returns stored by the
    reconciliation or a manual listing never move it). After a successful listing it is set to the instant the
    listing was **started** minus `WorkflowPolicy::pollingOverlap()` (a margin for the unpublished time zone, clock
    skew and comparison operator, §5.8; the overlap is harmless since returns are upserted). So a quiet tenant's range
    never grows beyond one cadence plus the overlap. First run: `now − WorkflowPolicy::initialLookback()`, capped at
    13 months (§1.2);
  - calls the one-date path from the cursor unless
    `WorkflowPolicy::pollingMaxRange()` is set, in which case it walks consecutive windows with the two-date path.
    `pollingMaxRange()` is `null` by default because the limit is not published (§5.7); it exists so that the limit
    found by a host can be configured without code;
  - upserts each `retour` under its identity `(tenant, flux.id, nature, ops)` (the uniqueness sentence of `nature`),
    an absent `ops` being the empty string (`ops` is "not set for a return by the SI DPAE", the origin of every DPAE
    return, §2.3, §3.1);
    `statut`, `publication`, `production`, `id` and `url` of a known return are updated when a later listing changes
    them. Returns with an unknown `nature` or `statut` are stored with their raw value ([ADR 0000](0000-reference-architecture.md) §4);
  - advances the cursor only after the whole answer (every window, with `pollingMaxRange()`) is stored; on `PollingConstraintViolated` the run stops for the
    tenant without moving the cursor (no automatic range splitting: the package does not guess the limit);
  - links each return to the local deposit with the same `flux.id` ([ADR 0005](0005-deposit-result-and-unknown-outcome.md))
    and records `ReturnAvailable` events for new returns and for returns that gain a document (`url` or `id`).
- `DownloadReturn` uses the `url` policy of [ADR 0002](0002-hosts-per-service.md); a return is due for download until
  `publication + WorkflowPolicy::downloadRetention()` (default 3 months, the published purge delay, §1.2), and a 404
  marks it `purged` ([ADR 0008](0008-doctrine-persistence.md)). Downloads are read-only: transport errors and 5xx are
  retryable.
- No local rate limiter. The polling cadence is the Scheduler frequency, a configuration option.

## Consequences

- Workers back off on both 422 and 429 and pick up again from the same cursor; nothing is lost.
- A host that learns the real range limit sets `polling_max_range`; until then the one-date path is used.
- The 24-hour reading and the time zone are open points (§5.8) checked by the contract tests; a zone error smaller
  than `pollingOverlap()` loses no return. The defaults of the polling tunables are package defaults, not
  specification values.
