# Implementation roadmap

Specification: [api-reference.md](api-reference.md). Architecture, conventions and quality gates are the same as in
`thebeardedbear/urssaf-tiers-prestations` (hexagonal layers enforced by Deptrac, PSR-only runtime, Symfony and
Doctrine bridges, 100 % coverage and MSI), see [ADR 0000](adr/0000-reference-architecture.md), with one major
difference: **this API is not OAuth2 and not JSON**. It uses an XML authentication call returning an opaque token, and
XML (plus PDF) payloads. The deviations are decided by ADR 0001 to 0009 (accepted 2026-11-22, [docs/adr/](adr/));
the first version covers **phase 1 (raw-flow deposit) + the declaration model** ([ADR 0007](adr/0007-declaration-model.md)).

## Already in place (skeleton)

- `SpecVersion::CURRENT` (`1.0.0`).
- `Infrastructure\Urssaf\Environment` (Sandbox / Production base URIs) and `Infrastructure\Urssaf\UrssafEndpoints`
  (Swagger paths under the base path `/authentifier_dpae`). **Both change first** ([ADR 0002](adr/0002-hosts-per-service.md)):
  - `Environment` and its test are deleted (no sandbox host; "production" is `DeclarationMode::Real`,
    [ADR 0003](adr/0003-declaration-mode-and-simulator-guard.md));
  - `ServiceEndpoints` holds four overridable base URIs (`mon.urssaf.fr`, `depot.dpae-edi.urssaf.fr`,
    `consultation.dpae-edi.urssaf.fr`, `telechargement.dpae-edi.urssaf.fr`), port `8443` added to the default
    non-authentication URIs of a concentrator identity;
  - `UrssafEndpoints` keeps the paths without the `/authentifier_dpae` prefix (only the authentication path is
    `/authentifier_dpae`).

## Domain (`src/Domain`)

Split into lots; each lot is merged green (100 % coverage, MSI 100 %).

### Lot 1 — value objects, sum types, exceptions

- `Declarant\DeclarantCredentials` (`siret`, `nom`, `prenom`, `motdepasse`, `service`; Swagger lengths only; private
  constructor, `of()`), `Declarant\Siret` (14 chars), `Declarant\ServiceCode` (raw int32 + `ServiceKind` `Direct` 25 /
  `Concentrator` 98) ([ADR 0001](adr/0001-xml-token-authentication.md)).
- `Authentication\DpaeToken` (opaque, never logged, never persisted), `Exception\AuthenticationFailure` (realm enum).
- `Declaration\DeclarationMode` (`Test|Real`), `Declaration\TestIndicators`, `Declaration\DpaeFlow` (raw XML via
  `fromXml($bytes, $mode, $indicators)`, always with its mode; the flow fingerprint is `Deposit\FlowFingerprint`)
  ([ADR 0003](adr/0003-declaration-mode-and-simulator-guard.md)).
- `Deposit\FlowId` (`IdFluxDPAE`, 1-50), `Deposit\FlowFingerprint`, `Deposit\RawDocument`, `Deposit\ElectronicReceipt`,
  `Deposit\RejectionNotice`, `Deposit\DepositResult` (sum type by convention)
  ([ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)).
- `Return\ReturnReference` (private constructor, `fromListing()`; flux id, nature, statut, publication, production, ops,
  delegataire, id, url; every value stored raw), `Return\ReturnId` (`idRetour` 1-10, checked at download),
  `Return\ReturnIdentity`, `Return\ReturnNature` (`10|11|20|41`, unknown preserved), `Return\ReturnStatus`
  (`OK|KO|ANO|EN_COURS`, unknown preserved), `Return\ReturnContent` (raw bytes + raw content type),
  `Return\ReturnContentType` ([ADR 0006](adr/0006-polling-constraints.md)).
- `Shared\Instant`, `Validation\*` (notification pattern), `Domain\Exception\*` (reference hierarchy,
  `CredentialsNotSerializable`, `PayloadNotSerializable`).

### Lot 2 — declaration model

- `Declaration\Declaration`, `Employer`, `Employee`, `Contract`, `ContractType` (`Cdd|Cdi|Ctt`), `DeclarationVocabulary`, `DeclarationRules` and the builder `Wire\DpaeFlowBuilder`,
  validated with the section 3.4 rules of [ADR 0007](adr/0007-declaration-model.md) (notification pattern, `normCode`
  parameter).

### Lot 5 — Application (domain parts)

- `Tenant\TenantId` ([ADR 0001](adr/0001-xml-token-authentication.md)), `Deposit\ExternalReference` (the host's
  identifier of the hire), the `Deposit\Deposit` aggregate with `DepositState` (`pending`, `depositing`, `received`,
  `rejected`, `failed`, `outcome_unknown`, `not_received`, `invalid_credentials`)
  ([ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)) and `Return\DownloadState`
  ([ADR 0006](adr/0006-polling-constraints.md)).

## Application (`src/Application`)

- Ports: `Port\Urssaf\DpaeGateway` (`deposit(TenantId, DpaeFlow): DepositResult`,
  `listReturns(TenantId, from, ?to): ReturnList`, `downloadReturn(TenantId, ReturnReference): ReturnContent`),
  `Port\Urssaf\OutcomeUnknown`, `Port\Urssaf\PollingConstraintViolated`, `Port\Urssaf\ReturnNoLongerAvailable`,
  `Port\Urssaf\ReturnNotDownloadable`, `CredentialsProvider` (`InMemoryCredentialsProvider`), `TokenStore`
  (in-memory only), `DepositRepository`, `ReturnRepository`, `ReturnContentStore`, `SynchronizationCursorRepository`,
  `TransactionManager`, `Outbox`, `EventPublisher`, `WorkflowPolicy` (every tunable of ADR 0005 / 0006).
- Commands/queries: `DepositDpae` (tenant, `ExternalReference`, `DpaeFlow`; the flow's mode is capped by the
  installation mode), `ListReturns` (one-date and two-date paths), `SynchronizeReturns` (dedicated cursor, upsert,
  optional `polling_max_range`),
  `DownloadReturn` / `DownloadDueReturns` (`url` honoured only on the configured download origin),
  `ReconcileUnknownDeposits` (declarant SIRET + fingerprint + publication window), `ResolveDepositOutcome`,
  `RequeueInvalidCredentials`, `GetWorkflowOverview`.

## Infrastructure (`src/Infrastructure`)

- `Urssaf\Http\DpaeTokenProvider` (XML `identifiants`, opaque token, replay once on `Expiration du jeton`) —
  **`OAuth2TokenProvider` from Tiers de prestations is not reusable** ([ADR 0001](adr/0001-xml-token-authentication.md)).
- `Urssaf\Http\XmlApiClient` (gzip request body, `Accept-Encoding: gzip`, decoding of still-gzipped answers,
  `Authorization: DPAELogin jeton=…`, no redirects), status mapping of [ADR 0004](adr/0004-xml-gzip-client.md).
- `Urssaf\UrssafHttpGateway` + `Urssaf\Wire\*`: `SafeXmlReader` (`LIBXML_NONET`, DOCTYPE refused, declared encoding
  honoured), `IdentifiantsXmlWriter`, `EnvoiXmlReader`, `RetoursXmlReader`, `DpaeFlowBuilder`
  (ISO-8859-1; `DeclarationVocabulary` and `DeclarationRules` live in the Domain), `yyyyMMddHHmmss` date codec with a configurable time zone.
- `Urssaf\ApiConfiguration`: four base URIs, `concentrator_port`, `declaration_mode`, `test_indicator.*`,
  `authentication_encoding`, `deposit_content_type`, `max_flow_bytes`, `returns_time_zone`, declaration vocabulary
  and name pattern.
- `Testing\SimulatedDpaeGateway` (refuses `Real`, no returns 20 / 41).

## Bridges

- Symfony bundle `UrssafDpaeBundle` (alias `urssaf_dpae`): configuration validated at build time, HTTP client with
  `max_redirects: 0`, `SimulatedGatewayFactory` (refuses the resolved `real` mode), console commands `urssaf:dpae:*`
  (`check`, `status`, `deposit <file> --mode`, `sync-returns`, `download-returns`, `reconcile`, `resolve`, `requeue`,
  `relay-outbox`), Messenger, locks and Scheduler prefixed `urssaf_dpae`, en / fr translations, Flex recipe in
  `recipe/` ([ADR 0009](adr/0009-coexistence.md)).
- Doctrine bridge ([ADR 0008](adr/0008-doctrine-persistence.md)): required for the full lifecycle, still an optional
  Composer dependency. Tables `urssaf_dpae_deposits`, `urssaf_dpae_deposit_flows` (opt-in), `urssaf_dpae_returns`,
  `urssaf_dpae_return_contents`, `urssaf_dpae_sync_cursors`, `urssaf_dpae_outbox`; DBAL types `urssaf_dpae_*`;
  returns kept beyond the 3-month purge, no local retention period. Schema documented in `docs/doctrine.md`; **no
  migration is shipped** (as the reference).
- Standalone builder `UrssafDpaeBuilder` (`withGateway()` refuses `real`).

## Runtime dependencies to add with the code

`ext-dom`, `ext-libxml`, `ext-zlib`, `ext-mbstring`, `psr/http-client`, `psr/http-factory`, `psr/http-message`,
`psr/clock`, `psr/log`, plus the matching `suggest` entries (Symfony and Doctrine bridges). `ext-json` is not needed by
the API itself. Symfony components in `require-dev` `^6.4.3 || ^7.4 || ^8.0`, Symfony 8.x in the CI matrix (PHP ≥ 8.4).

## Tests

- Unit: value objects, declaration validation (§3.4), XML writers/readers against `tests/Fixtures/wire/*` (the
  declaration writer's golden test is `deposit_request_minimal.xml`; the other wire examples are not published and
  must be captured, see api-reference §5), handlers with fake doubles, token provider (replay on expired token), 401
  realm mapping, `url` origin policy, reconciliation cases.
- Functional: Symfony bundle wiring (simulator guard, recipe), Doctrine mapping, repository contract traits.
- Contract (`make test-contract`, manual CI job): real credentials (an *Urssaf en ligne* subscriber, or a registered
  concentrator) in `URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`, `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD`,
  `URSSAF_DPAE_SERVICE`, optional `URSSAF_DPAE_*_URI` overrides. Always a `test` installation and
  `DeclarationMode::Test`, never real. First goals: settle hosts and paths, the test indicator values, the
  authentication XML shape, a built flow without raison sociale, and capture samples of the AEE, rejection notice,
  list response and returns.

## Order of work

1. Replace `Environment` / `UrssafEndpoints` (ADR 0002) and complete `deptrac.yaml` (Contract, Testing, Standalone).
2. Domain: lot 1 (value objects, sum types, exceptions), then lot 2 (declaration model and its validation).
3. Wire writers/readers + fixtures (`SafeXmlReader`, identifiants, envoi, retours, declaration writer).
4. HTTP gateway (authentication, deposit, lists, download, status mapping, 422 / 429).
5. Application commands/queries (deposit, synchronisation, download, reconciliation).
6. Symfony bridge and Flex recipe.
7. Doctrine bridge.
8. Contract tests, then remove the settled open points from `api-reference.md` §5 and amend the ADRs concerned.
