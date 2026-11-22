# ADR 0000 — Reference architecture inherited from urssaf-tiers-prestations

- Status: Proposed
- Date: 2026-11-22

## Context

Every URSSAF package of the family shares one architecture, so that integrators and maintainers move from one package
to another without relearning it. The reference implementation is `thebeardedbear/urssaf-tiers-prestations`
([TheBeardedBearSAS/urssaf-tiers-prestations](https://github.com/TheBeardedBearSAS/urssaf-tiers-prestations) at commit
[`ffec33f`](https://github.com/TheBeardedBearSAS/urssaf-tiers-prestations/tree/ffec33f)), read for this ADR from its
README ("Architecture", "Error handling", "Testing the package"), `deptrac.yaml`, `docs/api-reference.md` §4,
`docs/workflow.md`, `docs/symfony.md`, `docs/doctrine.md`, `docs/testing.md`, `phpstan.neon.dist`, `mago.toml` and
`rector.php`. The sibling package
[`urssaf-plateforme-numerique`](https://github.com/TheBeardedBearSAS/urssaf-plateforme-numerique) (ADR 0000, 0003 and
0005) is the precedent for the ADR format and for an API that transports binary files.

The DPAE API differs from the reference on points that touch the architecture (see [api-reference.md](../api-reference.md)
§1 and §4):

- **No OAuth2.** An XML `identifiants` body is posted to get an opaque base-64 token, then sent as
  `Authorization: DPAELogin jeton=…`; 401 answers are told apart by their `WWW-Authenticate` realm (§1.3).
- **XML, gzip and PDF, not JSON.** The deposit body is a gzip-compressed DPAE flow (XML in ISO-8859-1, or the flat
  format); answers are XML; returns are XML or PDF; error bodies are Apache HTML pages, never parsed.
- **Four hosts** (authentication, deposit, consultation, download), with port `8443` for concentrators, instead of one
  host plus a base path; the published Swagger and PDFs disagree (§5.1).
- **No sandbox host.** Test versus real is a field of the deposited flow (`FR_DUE_Upload.Test.Indicator` /
  `C_VERSION`, §3.3), so the same hosts receive both.
- **Returns are purged after 3 months** (404 afterwards) while the consultation history covers 13 months; polling is
  bounded by 422 / 429 answers whose limits are not published.
- **The declaration itself** (employer, employee, contract) is a business payload of this package, unlike the payment
  requests of the reference, which have a published JSON schema.
- **Doctrine** is required for the full lifecycle (deposits, returns and their contents kept beyond the purge), but it
  stays an optional Composer dependency (`require-dev` + `suggest`), as in the reference.
- **Docker only, no sandbox.** Every command runs in Docker; there is no sandbox to test against (see above), so
  contract tests hit the live hosts in test mode.

This ADR records what this package inherits from the reference, what it adapts, and what does not apply. Every
adaptation gets its own ADR, listed at the end.

## Decision

### Inherited principles

1. **Layers, enforced by Deptrac.** Hexagonal / DDD: `Domain` and `Contract` depend on nothing; `Application` →
   Domain, Contract; `Infrastructure` → Application, Domain, Contract, Psr; `Testing` → Domain, Contract, Application,
   Infrastructure; `DoctrineBridge` → Domain, Contract, Application, Infrastructure, Doctrine, Psr; `Standalone` →
   Domain, Contract, Application, Infrastructure, DoctrineBridge, Doctrine, Psr (no Symfony); `SymfonyBridge` →
   everything above plus Testing, Symfony, Doctrine, Psr. Tests are not analysed.
2. **PSR-only runtime.** `php >= 8.2`, `ext-*` and `psr/*` interfaces only; Symfony, Doctrine and the HTTP clients
   are `require-dev` + `suggest`, with the conflict floors of the reference.
3. **Value objects.** `final readonly`, private constructor, named constructors (`of`, `fromString`…), stable
   violation codes `<subject>.<reason>` in snake_case, no raw value in exception messages; credentials not
   serializable, secrets marked `#[SensitiveParameter]`, `__debugInfo` masking secrets and personal data. Validation
   follows the notification pattern (`Violation`, `ValidationResult`): every violation of an input is collected and
   thrown at once as `ValidationFailed`.
4. **Sum types.** Closed lists are backed enums; a value of a closed list read from URSSAF is kept raw next to a
   nullable enum (`fromCode(): ?self`), so an unknown value never breaks a read. Mutually exclusive outcomes are
   modelled as union types of `final readonly` classes (no flag plus nullable fields).
5. **Errors.** Every exception implements the `UrssafException` marker; transient failures also implement
   `RetryableException` (carrying a retry delay when known). Abstract `DomainException` (`violationCode()`,
   `params()`, `toViolation()`) with `InvalidValue`, `InvariantViolation`, `InvalidStateTransition`,
   `RecordNotFound`, `ValidationFailed`; gateway exceptions `UrssafRequestRejected`, `UrssafAuthenticationFailed`,
   `UrssafUnavailable` (`mayHaveReachedUrssaf()`), `UnexpectedUrssafResponse`.
6. **Application.** One `final readonly` handler per use case (`__invoke`), queries separated from commands, ports
   implemented by the outer layers, `UnitOfWork` around each state change, `WorkflowPolicy` holding every tunable.
7. **Events and outbox.** Aggregates record domain events, published by `UnitOfWork` after the commit (a failing
   listener never undoes the work). Public integration events live in `Contract\Event` (scalar, `TYPE` / `VERSION`,
   codec), are appended to the outbox inside the transaction of the state change and relayed at least once, in order
   per record.
8. **Multi-tenant.** `TenantId` on every aggregate and row, `CredentialsProvider::forTenant()`, unique constraints,
   claims and locks scoped by tenant.
9. **Idempotence, retry, claim.** A use case called twice with the same input returns the existing record; an atomic
   conditional claim (`UPDATE … WHERE state = :expected`) precedes any URSSAF write, so concurrent workers never send
   a record twice; attempts are counted and capped; a write that may have reached URSSAF is **never resent blindly**
   but reconciled or left for manual review; credential refusals park records in `invalid_credentials` until
   requeued; safety crons make the workflow converge without Messenger.
10. **Bridges.** Symfony: `AbstractBundle`, configuration validated at build time, console commands with `--tenant` /
    `--limit` / `--dry-run`, scalar Messenger messages, non-blocking locks per operation and tenant, Scheduler,
    installation check, en / fr translations in parity. Doctrine: XML mapping (`SimplifiedXmlDriver`), custom DBAL
    types, DBAL outbox, `DoctrineTransactionManager`, dedicated entity manager recommended. Standalone builder.
11. **Tests.** Unit / Functional / Contract suites. Hand-written doubles rather than mocks (`Fake*`, `InMemory*`,
    `Recording*`, `Fixed*`); repository contract traits run against the in-memory and the Doctrine implementations; a
    `Testing\Simulated…Gateway` for host applications, refused by the bundle outside tests; the Contract suite is
    opt-in (skipped without credentials, manual CI job). Gates: 100 % lines / methods / classes, MSI 100 % (no
    skipped, timed-out or syntax-error mutant), branch coverage at least 96 % (Xdebug path coverage).
12. **Quality.** PHPStan level max (bleeding edge, strict / ergebnis / shipmonk rules, type coverage 100 %), Mago
    `lint` + `analyze` (never `mago fmt`), Rector (PHP 8.2 sets, code quality, dead code, type declarations), PHP-CS-
    Fixer as the only formatter, Deptrac, composer checks, hadolint, guard. Every source file carries the SPDX MIT
    header; every commit carries a DCO `Signed-off-by`. Quality exceptions are only those justified in the reference
    configuration, plus those justified by an ADR of this package.

### Applicability to the DPAE package

| Theme | Verdict | Justification for this package |
|---|---|---|
| Layers and Deptrac | Applies, to complete | The local `deptrac.yaml` has Domain / Application / Infrastructure / bridges only. The `Contract` (integration events), `Testing` (simulator) and `Standalone` layers and their rules are added with the code that needs them. |
| PSR-only runtime | Applies | Runtime adds `ext-dom`, `ext-libxml`, `ext-zlib`, `ext-mbstring`; `ext-json` is not needed by the API. |
| Value objects, validation, masking | Applies | Password (`motdepasse`) and token are secrets; NIR, names and birth data of flows and returns are personal data, never logged nor shown by `__debugInfo`. |
| Sum types | Applies | Deposit result `ElectronicReceipt|RejectionNotice`; return nature (`10`, `11`, `20`, `41`), status and `service` code keep unknown raw values. |
| Error hierarchy | Adapted | Same markers; 401 is mapped by realm (expired password, wrong account, not registered, expired token); 422 means a rejection notice on deposit but a polling constraint on lists; HTML error bodies are discarded. See ADR 0001, 0005, 0006. |
| Authentication (OAuth2, `TokenStore` with safety margin) | Does not apply | No OAuth2 and no published token lifetime: XML token call, in-memory token only (it is a credential), reactive re-authentication on `Expiration du jeton`. ADR 0001. |
| Single host per environment, Sandbox / Production | Does not apply | Four hosts, port `8443` for concentrators, no sandbox host; every base URI overridable. The variant of a single Swagger host plus a base path ([api-reference.md](../api-reference.md) §4.2) is a discarded option, to be justified in ADR 0002. |
| Target environment as the safety switch | Adapted | Test vs real lives in the payload: a required `DeclarationMode`, never defaulted silently to real; the simulator is refused with the real mode instead of the production environment. ADR 0003. |
| JSON client and `Payload` reader | Adapted | XML / binary client, gzip request body, gzip-aware `Accept-Encoding`, ISO-8859-1 honoured, `LIBXML_NONET`, no DTD. ADR 0004. |
| Unknown outcome of a write | Adapted | No idempotency key on the deposit; a lost answer is reconciled from the return lists, never resent blindly. The reconciliation key is to be defined in ADR 0005 (it cannot be `IdFluxDPAE`, which is carried by the lost AEE). |
| Polling, quotas, rate limiter | Adapted | No published quota; 422 and 429 on lists are both a polling-constraint `RetryableException`; no local range check. ADR 0006. |
| Business payload modelling | Adapted | Declaration model and XML builder limited to the elements of the single official example plus the norm controls of §3.4. ADR 0007. |
| Events and outbox | Applies | Deposit recorded, receipt / rejection obtained, new return available, return downloaded; catalogue prefixed `urssaf.dpae.*`. |
| Multi-tenant | Applies | A tenant is one authenticating identity (direct declarant or concentrator); for a concentrator, the declarant SIRET is carried by the flow. |
| Idempotence, claim, attempts | Applies | Claim before each deposit; uniqueness per tenant on the host reference and on `IdFluxDPAE`. |
| Doctrine bridge | Adapted | Required for the full lifecycle but still an optional Composer dependency (`require-dev` + `suggest`): deposits, returns and downloaded contents are kept beyond the 3-month purge. Whether a migration is shipped (the reference ships none) is decided in ADR 0008. |
| Symfony bridge, Messenger, locks, Scheduler | Applies | Alias `urssaf_dpae`; commands for deposit, return synchronisation and download. |
| Coexistence with other URSSAF packages | Adapted | Prefixes for tables, DBAL types, services, locks, translation domain and events distinct from the other packages. ADR 0009. |
| Fake doubles, simulator, contract traits | Applies | `SimulatedDpaeGateway` answers AEE / rejection and serves returns per mode (no `20` / `41` in test mode). |
| Contract tests | Adapted | No sandbox: they run against the live hosts with real subscriber credentials, opt-in and manual, and always deposit in test mode. |
| Coverage 100 %, MSI 100 %, branches 96 % | Applies | Introduced by the lot 0: `make coverage-branches` (Xdebug path coverage, `bin/check-branch-coverage`, 96 % minimum) and a nightly CI job. |
| PHPStan max, Mago, Rector, PHP-CS-Fixer | Applies | Same configurations; local exceptions only with a justification. |
| SPDX headers and DCO | Applies | Introduced by the lot 0: SPDX MIT header (PHP CS Fixer `header_comment`, `bin/check-spdx` via `make guard`), `bin/check-dco`, CI job `dco` and the DCO section of `CONTRIBUTING.md`. |
| Batching by 10, per-operation quotas | Does not apply | Unit deposit only, no published quota. |
| Money in integer cents, ASCII transliteration | Does not apply | No amount in the DPAE; names follow the character sets of §3.4, not a transliteration. |
| Error-code catalogue (`ApiErrorCode`) | Does not apply | No structured error payload; business codes come in the returns (§3.4 – §3.6). |

## Consequences

- Integrators find the same layers, handlers, exceptions, events, commands and test doubles as in the other URSSAF
  packages; only the transport, the authentication and the declaration payload differ.
- The skeleton must change before any business code: `Environment` (Sandbox / Production) and the
  `/authentifier_dpae`-prefixed `UrssafEndpoints` are replaced (ADR 0002), and `deptrac.yaml` gains the missing
  layers.
- This ADR will supersede, once accepted, the "later phase" wording of [api-reference.md](../api-reference.md) §4.4 and
  [roadmap.md](../roadmap.md) for the declaration model: a limited model and builder are part of the first version
  (ADR 0007), the raw-flow deposit stays available. Those documents are aligned when ADR 0007 is accepted.
- Any further deviation from the reference needs its own ADR (Context / Decision / Consequences), accepted by the lead
  before the code that implements it; amendments are appended to the ADR they change, dated.

### Deviation ADRs to write

- **0001 — XML token authentication:** `DpaeTokenProvider` posts `identifiants`, keeps the opaque token in memory,
  re-authenticates once on `Expiration du jeton` and maps every 401 realm to a typed exception.
- **0002 — Hosts per service:** four overridable `base_uri` (authentication, deposit, consultation, download) with
  PDF defaults and the concentrator port, `Sandbox` removed, return `url` honoured for downloads.
- **0003 — `DeclarationMode` and simulator guard:** test / real is a required deposit option written into the flow,
  never defaulted to real, and the simulated gateway is refused with the real mode.
- **0004 — XML / gzip / ISO-8859-1 client:** XML and binary client replacing the JSON one, gzip request bodies,
  accepted response encodings, declared charset honoured, hardened libxml, HTML error pages ignored.
- **0005 — `DepositResult` and unknown outcome:** `ElectronicReceipt|RejectionNotice` sum type with the raw XML
  kept, and a lost deposit answer reconciled from the return lists instead of being resent.
- **0006 — Polling 422 / 429:** list calls mapped to a retryable polling-constraint exception, synchronisation from
  the last known publication date, no local range limit.
- **0007 — Declaration model:** employer / employee / contract model and XML builder limited to the official example
  elements plus the §3.4 norm controls, unknown vocabulary left open.
- **0008 — Doctrine persistence:** deposits, returns and their contents stored to outlive the 3-month purge, schema,
  claims, outbox and migration policy.
- **0009 — Coexistence:** distinct prefixes and identifiers so that this package runs side by side with the other
  URSSAF packages in one application and database.
