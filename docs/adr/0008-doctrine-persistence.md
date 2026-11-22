# ADR 0008 — Doctrine persistence of deposits and returns

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0005](0005-deposit-result-and-unknown-outcome.md),
  [ADR 0006](0006-polling-constraints.md), [ADR 0009](0009-coexistence.md)

## Context

- Returns available for download are retained **3 months**, then purged and not archived (download 404 afterwards);
  follow-up data stay in the consultation service **13 months** ([api-reference.md](../api-reference.md) §1.2, §2.5).
- The full lifecycle (claim, unknown outcome, reconciliation, return synchronisation and download) needs durable state
  ([ADR 0005](0005-deposit-result-and-unknown-outcome.md), [ADR 0006](0006-polling-constraints.md)).
- The reference persists with Doctrine ORM 3 / DBAL 4 as an optional Composer dependency (`require-dev` +
  `suggest`, conflict floors), XML mapping, custom DBAL types, a DBAL outbox, a dedicated entity manager recommended,
  and ships **no migration**: "the schema depends on your platform and on the rest of your schema" (reference
  `docs/doctrine.md` "Migrations").
- Flows and returns carry personal data (NIR, names, birth data, §4.13). No retention period is published for the
  host's copy; the specification only states URSSAF's own retention (§1.2).

## Decision

- Doctrine stays optional (`require-dev` + `suggest`, the reference's conflict floors); without it, the in-memory
  repositories and a host-provided `custom` driver remain available, as in the reference. The full lifecycle is
  documented as requiring durable repositories.
- Ports: `DepositRepository`, `ReturnRepository`, `ReturnContentStore`, `SynchronizationCursorRepository`,
  `TransactionManager`, `Outbox`. Each has an in-memory and a Doctrine implementation, both run against the same
  repository contract traits.
- Schema (prefix `urssaf_dpae_`, `DoctrineIntegration::TABLE_PREFIX`, [ADR 0009](0009-coexistence.md)):

  | Table | Content | Unique / indexes |
  |---|---|---|
  | `urssaf_dpae_deposits` | id, tenant, external reference, mode, fingerprint, declarant SIRET, size, state, attempts, claimed / updated instants, `IdFluxDPAE`, result kind (receipt / rejection), raw result document, last error code, concurrency version | unique `(tenant_id, flow_id)`; unique `(tenant_id, open_reference)` and `(tenant_id, open_fingerprint)`, columns equal to the external reference and the fingerprint while the deposit is open and `NULL` once it is closed (`failed`, `rejected`, `not_received`, [ADR 0005](0005-deposit-result-and-unknown-outcome.md)): a portable partial uniqueness; index `(tenant_id, state, updated_at)`, `(tenant_id, external_reference)` |
  | `urssaf_dpae_deposit_flows` | deposit id, gzip-compressed deposited flow | 1:1 with the deposit; kept only when `persistence.keep_flows` is `true` (default `false`) |
  | `urssaf_dpae_returns` | id, tenant, `flux.id`, deposit id (nullable), `nature` raw + parsed, `statut` raw + parsed, `ops` (non-null, empty string when absent), `delegataire`, `publication` raw + instant, `production` raw + instant, `retour.id`, `url`, download state (`pending`, `downloaded`, `not_downloadable`, `purged`), first-seen / updated instants | unique `(tenant_id, flow_id, nature, ops)` ([ADR 0006](0006-polling-constraints.md)); index `(tenant_id, publication_at)`, `(tenant_id, download_state, publication_at)` |
  | `urssaf_dpae_return_contents` | return id, content type, bytes, size, SHA-256, downloaded instant | 1:1 with the return |
  | `urssaf_dpae_sync_cursors` | tenant, cursor instant (moved only by `SynchronizeReturns`, [ADR 0006](0006-polling-constraints.md)), last successful listing instant, consecutive polling refusals | primary key `tenant_id` |
  | `urssaf_dpae_outbox` | integration events (`urssaf.dpae.*`) relayed at least once, ordered per record | as the reference |

- DBAL types are prefixed `urssaf_dpae_` (`urssaf_dpae_tenant_id`, `urssaf_dpae_flow_id`, `urssaf_dpae_siret`,
  `urssaf_dpae_fingerprint`, `urssaf_dpae_declaration_mode`…); `DoctrineIntegration::types()` lists them.
- The deposited flow is not stored by default: the fingerprint and the result document are enough to reconcile, and
  the flow is the most sensitive data. `keep_flows: true` is offered for hosts that must keep an exact copy.
- Purge and retention: `urssaf_dpae_returns` and their contents are **kept beyond the URSSAF purge**; that is the
  purpose of the bridge. The package defines **no local retention period** and deletes nothing on its own. It exposes
  the building blocks for the host's GDPR retention (repository `delete…Before(tenant, instant)` methods, documented in
  `docs/doctrine.md`); the host sets the period.
- Claims are conditional updates (`UPDATE … WHERE state = :expected`) on `urssaf_dpae_deposits`; aggregates carry an
  optimistic-lock version, as in the reference.
- **No migration file is shipped**, as in the reference: the schema is documented in `docs/doctrine.md` (tables,
  columns, constraints, indexes, types) and generated by the host with `doctrine:migrations:diff`. A dedicated entity
  manager is recommended, with the reference's wording.

## Consequences

- Returns downloaded within the 3-month window stay available to the host after URSSAF's purge; a return first seen
  after it is marked `purged` on its 404.
- Hosts must protect the tables holding personal data (`urssaf_dpae_return_contents`, `urssaf_dpae_deposits` result
  documents, `urssaf_dpae_deposit_flows` when enabled): encryption at rest, access control and retention are theirs,
  documented in `docs/doctrine.md` "Personal data" and `docs/security/assurance-case.md`.
- This replaces "includes the migration" in [roadmap.md](../roadmap.md): the package ships none.

## Amendment 2026-11-22 — ports and in-memory adapters (lot 5)

- The cursor port is named `SyncCursorRepository` (`SynchronizationCursorRepository` above); the content store is
  `ReturnContentStore`. Both live with the other ports in `Application\Port`, with their in-memory adapters
  (`InMemoryDepositRepository`, `InMemoryReturnRepository`, `InMemorySyncCursorRepository`, `InMemoryReturnContentStore`,
  `InMemoryOutbox`, `ImmediateTransactionManager`). In-memory repositories keep aggregates by reference, are not shared
  between processes and make the claim atomic within one process only: they are for tests and trials, not for the full
  lifecycle.
- `Standalone\Persistence` groups the repositories, the content store, the outbox and the transaction manager, because they
  must share one store; `UrssafDpaeBuilder::withPersistence()` replaces them as a whole.
- The Doctrine implementations of these ports are for the Doctrine lot; `DepositRepository::withoutFlowId()` and
  `staleDepositing()` are the two queries they must add to the unique-constraint reads of this ADR.

## Amendment 2026-11-22 — the concurrency contract of `DepositRepository` (lot 5 review)

What the Doctrine adapter implements, as the in-memory one does with a stored version per deposit:

- **Version.** `urssaf_dpae_deposits` has an integer version column (the "concurrency version" above), moved by one at every
  save, the insert included. `save()` is `UPDATE … SET version = version + 1 … WHERE id = :id AND version = :read`; no row
  updated means `Domain\Exception\ConcurrentModification` (`deposit.concurrent_modification`), after which the aggregate is
  discarded. Doctrine's `#[Version]` lock does this; the aggregate exposes `Deposit::version()` / `advanceVersion()` for a
  mapping that cannot read the column.
- **Claim.** `claim($id, $now, $maxAttempts)` is `UPDATE … SET state = 'depositing', attempts = attempts + 1, claimed_at = :now,
  version = version + 1 WHERE id = :id AND state = 'pending' AND attempts < :max`: true when a row was updated. A deposit read before
  a claim therefore cannot overwrite it.
- **Queries.** `withoutFlowId($tenant, $limit)` and `staleDepositing($tenant, $before, $limit)` order by `claimed_at`, then id, and
  limit in the query; index `(tenant_id, state, claimed_at)` serves both.
- **`get(DepositId)`** is not scoped by tenant: the id is generated by the package and only comes from a tenant-scoped read.
- Callers retry a lost race through `Application\Service\DepositUpdater` (reload, decide again, three attempts) and never
  catch the exception elsewhere.
