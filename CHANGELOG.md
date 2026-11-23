# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Contract tests, benchmarks and documentation (lot 8): `tests/Contract` (`LiveContractTest`, `LiveGateway`, `SampleRecorder`), live
  checks of the hosts, the authentication XML, the date formats, the list and download calls and, opt-in with
  `URSSAF_DPAE_CONTRACT_DEPOSIT=1`, a test-mode deposit; skipped without `URSSAF_DPAE_SIRET`, `_NOM`, `_PRENOM`, `_PASSWORD` and
  `_SERVICE` (optional `URSSAF_DPAE_{AUTHENTICATION,DEPOSIT,CONSULTATION,DOWNLOAD}_URI`), always `DeclarationMode::Test`, answers
  kept as local samples in the git-ignored `var/contract-samples` (never the authentication exchange); `make test-contract` and the
  manual CI job `contract` pass the same variables. PHPBench benchmarks `DeclarationValidationBench`, `DpaeFlowEncodingBench` and
  `WireDecodingBench` (`make bench`, CI job `benchmark`). [docs/workflow.md](docs/workflow.md) (state machines, idempotence, retry,
  reconciliation, outbox, polling), [docs/testing.md](docs/testing.md), the final README (standalone and Symfony quick starts),
  and the roadmap and the open points of api-reference.md brought up to date.
- Symfony bridge (lot 6, ADR 0003, 0009, `symfony/*` stay `require-dev` + `suggest`, floors `^6.4.3 || ^7.4 || ^8.0`):
  `UrssafDpaeBundle` (alias `urssaf_dpae`), configuration validated at build time (credentials through `%env()%`, service
  code `25` / `98` / any integer kept, four overridable base URIs and the concentrator port, `declaration_mode` ceiling
  defaulting to `test`, `gateway.simulated` refused with `real` at build time and, through `SimulatedGatewayFactory`, at
  runtime), an HTTP client with `max_redirects: 0`, console commands `urssaf:dpae:{check,deposit,returns,download,sync-returns
  (alias sync),reconcile,relay-outbox,purge-outbox}`, Messenger messages and handlers, operation locks
  `urssaf_dpae.<operation>.<scope>`, the Scheduler schedule `urssaf_dpae`, installation checks, en / fr translations, the
  Flex recipe `recipe/thebeardedbear/urssaf-dpae/0.1` with `bin/build-flex-endpoint` and `make flex-endpoint`, and
  [docs/symfony.md](docs/symfony.md). New application pieces: the `IntegrationEventSink` port, `RelayOutbox` and `PurgeOutbox`.
  The token stays in memory (no cache pool shipped, ADR 0001). Credentials are built per tenant on demand; lock names escape
  `.` and `%`; `download` writes `0600` and needs `--force` to overwrite; CI job `tests (PHP 8.4, Symfony 8)`
  (`bin/require-symfony8`, `doctrine/doctrine-bundle` `^2.19 || ^3.0`).
- Doctrine bridge (lot 7, ADR 0008, 0009, optional: `doctrine/orm`, `doctrine/dbal`, `doctrine/doctrine-bundle` stay `require-dev` + `suggest`, with
  the `conflict` floors): `Bridge\Doctrine\{DoctrineDepositRepository, DoctrineReturnRepository, DoctrineReturnContentStore,
  DoctrineSyncCursorRepository, DoctrineOutbox, DoctrineTransactionManager, DoctrineDepositFlowStore, DoctrineIntegration}`.
  XML mapping of six row entities and nine DBAL types, all prefixed `urssaf_dpae_` (tables `urssaf_dpae_deposits`,
  `_deposit_flows`, `_returns`, `_return_contents`, `_sync_cursors`, `_outbox`; indexes `idx_urssaf_dpae_*` /
  `uniq_urssaf_dpae_*`). The repositories write with DBAL, not the unit of work: the claim is one conditional `UPDATE`, the
  deposit carries an optimistic version (`deposit.concurrent_modification`, the entity manager stays open), `withoutFlowId()`
  and `staleDepositing()` order by claim then id and limit in the query, open reference and fingerprint are unique per tenant
  through two derived columns, savepoints for nested transactions. Returns and their contents are kept beyond the URSSAF
  3-month purge; the package sets no retention period, the host calls `deleteDownloadedBefore()`, `deleteSeenBefore()`,
  `deleteFinishedBefore()` and `Outbox::purgeDispatchedBefore()`. Schema and personal-data guidance in
  [docs/doctrine.md](docs/doctrine.md); **no migration is shipped**. The in-memory and the Doctrine adapters pass the same
  repository contract traits (functional tests on SQLite, in memory).
  Erasure by key (`DoctrineDepositRepository::delete()`, `deleteByReference()`, `DoctrineDepositFlowStore::delete()`);
  `DoctrineIntegration::doctrineBundleConfigurationFor('urssaf')` maps a dedicated entity manager; a `downloaded` return is not
  overwritten by a stale synchronisation, and is "no longer available" without a call once its document was purged; a
  reference held by an open deposit of the same fingerprint is `deposit.concurrent_modification` (retryable).
- Application layer (lot 5, ADR 0003, 0005, 0006, 0008): `Domain\Deposit\{Deposit, DepositId, DepositState, ExternalReference}`
  (the ADR 0005 lifecycle), `Domain\Return\{TrackedReturn, DownloadState, SyncCursor}`, domain events and the public
  integration events `Contract\Event\*` (`urssaf.dpae.*`, codec); the handlers `DepositDpae` (idempotent per tenant by
  reference and flow fingerprint, atomic claim before the send, unknown outcome never resent), `ListReturns`,
  `DownloadReturn`, `SynchronizeReturns` (per-tenant cursor, polling refusals reported) and `ReconcileUnknownDeposits`;
  the ports `DepositRepository`, `ReturnRepository`, `SyncCursorRepository`, `ReturnContentStore`, `TransactionManager`,
  `Outbox`, `EventPublisher`, `FailureReporter`, `IdGenerator`, `Clock`, `WorkflowPolicy` and `UnitOfWork`; their in-memory
  adapters; `Testing\SimulatedDpaeGateway` and `Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory` (test mode only);
  and the standalone entry point `Standalone\UrssafDpaeBuilder` / `UrssafDpae`. `deptrac.yaml` gains the `Contract`,
  `Testing` and `Standalone` layers. The reconciliation does not download the AEE yet (api-reference.md §5.23).
  Deposits are saved under an optimistic version (`deposit.concurrent_modification`; sweep, adoption and late answers reload and
  decide again); the reconciliation adopts only once a window is closed and the policy allows it (`WorkflowPolicy::autoAdopt`),
  marks adoptions (`urssaf.dpae.deposit.adopted`), caps its listing to 13 months and `now`, reports refusals and unattached flux;
  `urssaf.dpae.deposit.failed` and `.parked` are published.

- Project skeleton: Docker tooling, quality gates (`make ci`) and GitHub Actions CI, identical to the other URSSAF
  packages.
- Specification: official OpenAPI contracts (`docs/openapi/`), official documentation (links in `docs/api-reference.md`, local copy in the git-ignored `docs/reference/`), technical
  reference (`docs/api-reference.md`) and implementation roadmap (`docs/roadmap.md`).
- Signed releases (workflow `release.yml`): each GitHub release carries the distribution archive, its `SHA256SUMS`
  and a SLSA build provenance attestation (`*.intoto.jsonl`, Sigstore); see *Verifying a release* in SECURITY.md.
- CodeQL analysis of the GitHub Actions workflows (workflow `codeql.yml`) on every pull request and push to `main`.
- CI: branch coverage is measured (Xdebug path coverage, `make coverage-branches`, `bin/check-branch-coverage`) and
  enforced at 96 % minimum. The job `coverage-branches` runs on `main`, nightly (the CI workflow now has a nightly
  schedule) and on demand.
- Every source file carries a copyright and `SPDX-License-Identifier: MIT` header, enforced by PHP CS Fixer
  (`header_comment`) for PHP and by `make guard` (`bin/check-spdx`) for the other source files.
- Developer Certificate of Origin: every commit is signed off (`git commit -s`), checked on each pull request by the
  CI job `dco` (`bin/check-dco`).
- Symfony `^8.0` is supported: the Symfony components of the `require-dev` and `conflict` floors now accept `^8.0`.
- CI: the `contract` job reads the `URSSAF_DPAE_*` secrets and variable (`URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`,
  `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD`, `URSSAF_DPAE_SERVICE`, optional host overrides `URSSAF_DPAE_{AUTHENTICATION,DEPOSIT,CONSULTATION,DOWNLOAD}_URI`, the names of the Flex recipe, ADR 0009).
- Project governance: `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1), `GOVERNANCE.md` (decision model, roles and
  responsibilities), `ROADMAP.md`, and the code review requirements and first-contribution tasks in `CONTRIBUTING.md`.
- Security assurance case (threat model, trust boundaries, secure design principles, countered weaknesses) in
  `docs/security/assurance-case.md`, linked from `SECURITY.md`.
- HTTP gateway (lot 4, ADR 0001, 0002, 0004 to 0006): ports `Application\Port\Urssaf\DpaeGateway`
  (`deposit`, `listReturns`, `downloadReturn`), `CredentialsProvider`, `TokenStore` and the gateway exceptions
  (`UrssafUnavailable`, `UrssafAuthenticationFailed`, `UrssafRequestRejected`, `PollingConstraintViolated`,
  `ReturnNoLongerAvailable`, `ReturnNotDownloadable`, `OutcomeUnknown`); `Domain\Tenant\TenantId`; the PSR-18 / PSR-17
  adapter `UrssafHttpGateway` built by `UrssafGatewayFactory` (`ApiConfiguration`, `ServiceEndpoints` with four
  overridable base URIs and the concentrator port, `GatewayOptions`); XML token authentication kept in memory
  (`DpaeTokenProvider`, `InMemoryTokenStore`, `InMemoryCredentialsProvider`), a single replay on `Expiration du jeton`,
  gzip request and answer bodies, a size limit on answers, and a deposit that is never resent (`OutcomeUnknown`).
- Installation ceiling `ApiConfiguration::withDeclarationMode()` (default `Test`): the gateway refuses a `Real` flow
  before any call (`declaration_mode.not_allowed`, ADR 0003 amendment); `http` base URIs are refused for the published
  URSSAF hosts; `TenantId` refuses control characters.
- Runtime requirements: `ext-zlib`, `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/clock`, `psr/log`
  (`suggest`: a PSR-18 client and PSR-17 factories).
- Architecture decision records in `docs/adr/`, starting with ADR 0000 (reference architecture inherited from
  `urssaf-tiers-prestations`).

### Changed

- `Infrastructure\Urssaf\Environment` (Sandbox / Production) is removed and `UrssafEndpoints` loses the
  `/authentifier_dpae` prefix: there is no sandbox host, the four hosts are overridable (ADR 0002).

- Infection: `timeout` raised from 30 s to 180 s, and `make infection` and the CI job fail when a mutant is skipped (Infection never runs a mutant whose covering tests exceed the limit, yet reports MSI 100 %).
- Infection: a timed-out mutant counts as escaped (`timeoutsAsEscaped`), and `make infection` and the CI job fail on any timed-out or syntax-error mutant: every mutant really runs and is killed.
- CI: verifiable quality proofs (Codecov, Stryker Dashboard, SonarQube Cloud, OpenSSF Scorecard; active once the
  repository is public), every GitHub Action pinned to a commit SHA and kept up to date by Dependabot, hadolint on the
  Dockerfile (`make hadolint`, part of `make ci`) with a non-root development image, and benchmarks with PHPBench
  (`make bench`, CI job `benchmark`, isolated in `tools/phpbench`).
- CI: the SonarQube Cloud analysis also requires the repository variable `SONARCLOUD=enabled`, set once the
  project is imported on SonarQube Cloud.

- README: maintainer (The Bearded Bear), the other URSSAF packages and professional support; composer
  `authors` now points to https://the-bearded-bear.com.

- The package is open source under the MIT license (previously proprietary). Added `CONTRIBUTING.md` and
  `SECURITY.md`, and a notice in the README stating that the package is unofficial and not affiliated with URSSAF.

### Fixed

- `ListingWindows::split()` always terminates: the loop exits on the window reaching the end of the period instead of testing the start
  against it, so a faulty comparison (e.g. an Infection mutant) gives wrong windows, caught by the tests, instead of an endless loop
  that surfaced as a flaky timed-out mutant in CI.
