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
  `when@test: urssaf_dpae: { gateway: { simulated: true } }`), checked by a `FlexRecipeTest`.
- **Static credentials** adapter named `InMemoryCredentialsProvider` (as five of the six surveyed packages).
- **Host overrides** for every service, the authentication included, from configuration and `%env()%`, so that the
  mock server replaces URSSAF with no other change ([ADR 0002](0002-hosts-per-service.md)).
- **Prefixes**:

  | Resource | Prefix / name |
  |---|---|
  | Tables | `urssaf_dpae_*` ([ADR 0008](0008-doctrine-persistence.md)) |
  | DBAL types | `urssaf_dpae_*` |
  | Locks | `urssaf_dpae.<operation>.<tenant>` (non-blocking, per operation and tenant) |
  | Scheduler | schedule `urssaf_dpae` (transport `scheduler_urssaf_dpae`) |
  | Messenger messages | `TheBeardedBear\UrssafDpae\Bridge\Symfony\Messenger\*`, scalar payloads |
  | Cache keys | `urssaf_dpae.*`, reserved: the package caches nothing today (the token stays in process memory, [ADR 0001](0001-xml-token-authentication.md)); any future cache entry uses this prefix |

- **Simulator guard**: `SimulatedGatewayFactory` resolves the installation `declaration_mode` at runtime and refuses
  `real`, on top of the build-time check of a literal value; the standalone builder's `withGateway()` refuses `real`
  ([ADR 0003](0003-declaration-mode-and-simulator-guard.md)).
- **Symfony 8.x**: `require-dev` `^6.4.3 || ^7.4 || ^8.0` for the Symfony components; the CI matrix includes a Symfony
  8.x entry on PHP ≥ 8.4 (the PHP floor of Symfony 8). The bundle uses only APIs available in the three majors.

## Consequences

- The package can be installed next to the seven others with no rename and no configuration of names.
- The `urssaf:dpae:check` command reports the declaration mode and the four resolved base URIs (no secret).
- Any new console command, table, type, lock, schedule or cache key follows these prefixes; a review rejects the
  others.
