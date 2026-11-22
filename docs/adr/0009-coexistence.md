# ADR 0009 — Coexistence with the other URSSAF packages

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0002](0002-hosts-per-service.md),
  [ADR 0003](0003-declaration-mode-and-simulator-guard.md), [ADR 0008](0008-doctrine-persistence.md)

## Context

The eight URSSAF packages of the family are installed together in one Symfony 8 application and tested against one
HTTP mock server. A survey of the first six packages found collisions: console commands without namespace
(`urssaf:check` defined by two packages), unprefixed environment variables (`URSSAF_ENV`), a package without Flex
recipe, and identifiers that must stay distinct in a shared database, lock store, scheduler and cache. This ADR is a
package-level decision: nothing in the DPAE specification constrains these names.

## Decision

- **Short slug** `dpae`; bundle alias `urssaf_dpae`; service ids `urssaf_dpae.*` (`ServiceIds`); translation domain
  `urssaf_dpae` (en / fr in parity); integration events `urssaf.dpae.*`.
- **Console commands** all under `urssaf:dpae:` — `urssaf:dpae:check` (installation check), `urssaf:dpae:status`,
  `urssaf:dpae:deposit <file> --mode=test|real` (the mode is required, [ADR 0003](0003-declaration-mode-and-simulator-guard.md)),
  `urssaf:dpae:sync-returns`, `urssaf:dpae:download-returns`, `urssaf:dpae:reconcile`, `urssaf:dpae:resolve`,
  `urssaf:dpae:requeue`, `urssaf:dpae:relay-outbox`, with `--tenant` / `--limit` / `--dry-run` where they apply. No
  command outside this namespace.
- **Environment variables** of the recipe, all prefixed `URSSAF_DPAE_`: `URSSAF_DPAE_DECLARATION_MODE` (`test`),
  `URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`, `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD`, `URSSAF_DPAE_SERVICE`, and the
  optional host overrides `URSSAF_DPAE_AUTHENTICATION_URI`, `URSSAF_DPAE_DEPOSIT_URI`, `URSSAF_DPAE_CONSULTATION_URI`,
  `URSSAF_DPAE_DOWNLOAD_URI` ([ADR 0002](0002-hosts-per-service.md)). Never an unprefixed `URSSAF_*` variable. The
  contract tests read the same names.
- **Flex recipe** in `recipe/thebeardedbear/urssaf-dpae/0.1/` (`manifest.json` registering the bundle and the env
  variables above with safe defaults, `config/packages/urssaf_dpae.yaml` commented like the CESU recipe, with
  `when@test: urssaf_dpae: { gateway: { simulated: true }, credentials: <a fictitious declarant> }`, so that the test
  environment needs no `URSSAF_DPAE_*` variable), checked by a `FlexRecipeTest`.
- **Static credentials** adapter named `InMemoryCredentialsProvider` (as five of the six surveyed packages); the bundle
  wires the credentials of its configuration through `LazyCredentialsProvider`, which builds each tenant on demand (see the
  amendment).
- **Host overrides** for every service, the authentication included, from configuration and `%env()%`, so that the
  mock server replaces URSSAF with no other change ([ADR 0002](0002-hosts-per-service.md)).
- **Prefixes**:

  | Resource | Prefix / name |
  |---|---|
  | Tables | `urssaf_dpae_*` ([ADR 0008](0008-doctrine-persistence.md)) |
  | DBAL types | `urssaf_dpae_*` |
  | Locks | `urssaf_dpae.<operation>.<scope>` (non-blocking; the scope parts escape `%` and `.`, see the amendment) |
  | Scheduler | schedule `urssaf_dpae` (transport `scheduler_urssaf_dpae`) |
  | Messenger messages | `TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\*`, scalar payloads |
  | Cache keys | `urssaf_dpae.*`, reserved: the package caches nothing today (the token stays in process memory, [ADR 0001](0001-xml-token-authentication.md)); any future cache entry uses this prefix |

- **Simulator guard**: `SimulatedGatewayFactory` resolves the installation `declaration_mode` at runtime and refuses
  `real`, on top of the build-time check of a literal value; the standalone builder's `withGateway()` refuses `real`
  ([ADR 0003](0003-declaration-mode-and-simulator-guard.md)).
- **Symfony 8.x**: `require-dev` `^6.4.3 || ^7.4 || ^8.0` for the Symfony components and `doctrine/doctrine-bundle`
  `^2.19 || ^3.0` (3.x needs PHP ≥ 8.4 and Symfony 8); the CI job `tests (PHP 8.4, Symfony 8)` resolves Symfony 8.x and
  runs the unit and functional suites (`bin/require-symfony8`: composer has no `SYMFONY_REQUIRE` wildcard, and the QA tools
  that pin `symfony/console` below 8 are dropped for that job only). The bundle uses only APIs available in the three majors.

## Consequences

- The package can be installed next to the seven others with no rename and no configuration of names.
- The `urssaf:dpae:check` command reports the declaration mode and the four resolved base URIs (no secret).
- Any new console command, table, type, lock, schedule or cache key follows these prefixes; a review rejects the
  others.

## Amendment 2026-11-22 — the Symfony bridge as implemented (lot 6)

- **Commands.** Primary names are `urssaf:dpae:check`, `urssaf:dpae:deposit <file> --mode --reference [--tenant] [--dry-run]`,
  `urssaf:dpae:returns [--from] [--to]`, `urssaf:dpae:sync-returns` (alias `urssaf:dpae:sync`), `urssaf:dpae:reconcile`,
  `urssaf:dpae:relay-outbox` and `urssaf:dpae:purge-outbox`. `urssaf:dpae:download <flowId> <nature> [--ops] --output=<file>`
  takes the identity of the return (flux, nature, ops) because `DownloadReturn` is keyed by it, not by the legacy return `id`.
- **Deferred.** `status`, `resolve`, `requeue` and `download-returns` are not registered: their use cases
  (`GetWorkflowOverview`, `ResolveDepositOutcome`, `RequeueInvalidCredentials`, `DownloadDueReturns`) do not exist yet.
  `RelayOutbox`, `PurgeOutbox` and the `IntegrationEventSink` port are delivered with this lot.
- **Token.** No PSR-6 token store and no cache pool are shipped ([ADR 0001](0001-xml-token-authentication.md)): the token stays
  in memory. The name `urssaf_dpae.token_cache` is only reserved; `token_store.service` accepts a `TokenStore` of the host.
- **Messenger.** Messages and handlers live in `Bridge\Symfony\Messenger\*` (`DepositDpaeMessage`, `SynchronizeReturnsMessage`,
  `ReconcileDepositsMessage`); the deposit message carries the base-64 flow and the mode.
- **Not exposed.** The declaration vocabulary and name pattern options of the builder are not configurable in the bundle: no
  entry point builds declarations (the deposit command and message take a ready XML flow).
- **Locks.** The scope of a lock is the tenant (`sync-returns.<tenant>`, `reconcile.<tenant>`), the tenant and the reference for a
  deposit (`deposit.<tenant>.<reference>`: two deposits of one tenant may run side by side, the claim still protects each one) and `*` for
  the outbox commands (`relay-outbox.*`, `purge-outbox.*`). The scope is a list of parts and each part escapes `%` (`%25`) and
  `.` (`%2E`), so that tenant `a.b` with reference `c` never shares the lock of tenant `a` with reference `b.c`; the tenant and the
  reference are validated before the lock is taken (Messenger handlers included), and its name is never built from a raw message.
- **Credentials on demand.** The bundle builds the credentials of each tenant when that tenant is first used
  (`LazyCredentialsProvider`): a missing `%env()%` variable or an invalid value breaks that tenant only, never the boot of the
  application nor the other tenants. The `when@test` block of the recipe carries a fictitious declarant, so a test
  environment boots with no variable.
- **`declaration_mode`.** The configuration tree accepts the empty string (the dummy value of an `%env()%` placeholder during
  the validation of the container, indistinguishable from a literal one); an empty or unknown resolved value is refused at
  runtime by `InstallationModeFactory` with a message that names the option.
- **Logs.** The `monolog.logger` channel `urssaf_dpae` is tagged on the definitions that receive the logger
  (`FailureReporter`, the gateway options, the probe token provider), not on the gateway that only holds them.
- **Downloads.** `urssaf:dpae:download` writes the document readable by its owner only (`0600`) and refuses to overwrite an
  existing `--output` file unless `--force` is given; `urssaf:dpae:returns` lists the `ops` of each return (`-` when none) so
  that it can be passed to `--ops`.
- **Coexistence variables of the contract tests.** The `contract` CI job and `make test-contract` read the recipe variables
  (`URSSAF_DPAE_SIRET`, `_NOM`, `_PRENOM`, `_PASSWORD`, `_SERVICE` and the four optional `*_URI` host overrides); the single
  `URSSAF_DPAE_BASE_URI` of the first draft is gone, since the four hosts differ ([ADR 0002](0002-hosts-per-service.md)).
- **Symfony 8 in CI.** Obtained: Symfony 8.1 with DoctrineBundle 3.4 and ORM 3.8 on PHP 8.4, 1898 unit and functional tests
  green. The test application drops the `enable_lazy_ghost_objects` option on DoctrineBundle 3 (removed there); the bundle
  needs no change. The static analysis and the QA tools stay on the locked PHP 8.2 dependencies.
