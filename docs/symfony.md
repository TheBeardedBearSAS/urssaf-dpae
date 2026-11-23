# Symfony bundle

`UrssafDpaeBundle` (alias `urssaf_dpae`) wires the package into a Symfony 6.4, 7.x or 8.x application: HTTP gateway,
credentials, persistence, console commands, Messenger handlers, locks, Scheduler and an installation check. The Symfony
components are `require-dev` (and `suggest`) of the package: install the ones you use.

```
composer require thebeardedbear/urssaf-dpae symfony/http-client symfony/lock symfony/messenger symfony/scheduler
```

The Flex recipe (see "Flex recipe") registers the bundle and copies `config/packages/urssaf_dpae.yaml`. Without Flex, add
`TheBeardedBear\UrssafDpae\Bridge\Symfony\UrssafDpaeBundle::class => ['all' => true]` to `config/bundles.php`.

## Configuration reference

| Key | Type | Default | Meaning |
|---|---|---|---|
| `declaration_mode` | `test` \| `real` | `test` | The **highest** mode this installation may deposit (ADR 0003). Usually `%env(URSSAF_DPAE_DECLARATION_MODE)%`. `real` is never a default. |
| `hosts.authentication` / `.deposit` / `.consultation` / `.download` | string \| null | NAT/5492 hosts | Base URI of each service, every one overridable (authentication included), e.g. `%env(default::URSSAF_DPAE_DEPOSIT_URI)%`. A path prefix is kept. An overridden URI is used verbatim (no port added). |
| `concentrator_port` | int | `8443` | Port added to the **default** deposit, consultation and download URIs of a concentrator (service code `98`). |
| `authentication_encoding` | string | `UTF-8` | XML encoding of the `identifiants` body (not published). |
| `deposit_content_type` | string | `text/plain` | `Content-Type` of the deposit request. |
| `max_flow_bytes` | int | `100000000` | Local limit of the uncompressed flow. |
| `max_response_bytes` | int | `100000000` | Limit of a decoded answer. |
| `returns_time_zone` | string | `Europe/Paris` | Time zone of the list paths and of `publication` / `production`. |
| `test_indicator.real` / `.test` | string | `120` / `TST` | Value of `FR_DUE_Upload.Test.Indicator` per mode (inferred, not published). They must differ. |
| `http_client` | service id | dedicated client | PSR-18 client. It **must not follow redirects** (a 307 / 308 would resend the deposit). |
| `http_timeout` | float | `30.0` | Timeout of the default client (which sets `max_redirects: 0`). |
| `credentials` | map | - | Credentials by tenant id (see below). Exclusive with `credentials_provider`. |
| `credentials_provider` | service id | - | A `CredentialsProvider` of your own (then list the tenants in `tenants`). |
| `tenants` | list | keys of `credentials` | Tenants processed by commands that do not receive `--tenant`. |
| `token_store.service` | service id | in memory | A `TokenStore` of your own. |
| `gateway.simulated` | bool | `false` | Tests only: the in-memory `Testing\SimulatedDpaeGateway`. |
| `lock.enabled` / `.factory` / `.ttl` | `auto`\|bool / id / float | `auto` / `lock.factory` / `3600` | Operation locks. |
| `outbox.enabled` / `.service` / `.sink` / `.bus` / `.retention` | | `false` / - / `messenger` / `messenger.default_bus` / `P30D` | Outbox of integration events. |
| `scheduler.enabled`, `scheduler.frequencies.{sync_returns, reconcile, relay_outbox, purge_outbox}` | | `false`; `PT15M`, `PT15M`, `PT1M`, `P1D` | Scheduler schedule; `null` leaves a task out. |
| `workflow.*` | | package defaults | `max_deposit_attempts` (3), `stale_deposit_after` (PT15M), `reconciliation_skew` (PT5M), `reconciliation_window` (PT1H), `manual_review_after` (PT6H), `polling_overlap` (PT2H), `initial_lookback` (P7D), `polling_max_range` (null), `polling_retry_delay_seconds` (60), `max_polling_constraint_retries` (5), `auto_adopt` (null: decided per deposit). |
| `persistence.driver` | `doctrine` \| `in_memory` \| `custom` | `doctrine` when the ORM is installed | See "Persistence". |
| `persistence.entity_manager` | string | `default` | Doctrine entity manager (use a dedicated one). |
| `persistence.deposit_repository`, `return_repository`, `sync_cursor_repository`, `return_content_store`, `transaction_manager` | service ids | - | Required by the `custom` driver. |
| `persistence.keep_flows` | bool | `false` | Doctrine only: exposes the public `DoctrineDepositFlowStore` service. |
| `messenger.enabled` | bool | `false` | Registers the Messenger handlers. |

### Credentials

```yaml
urssaf_dpae:
    declaration_mode: '%env(URSSAF_DPAE_DECLARATION_MODE)%'
    credentials:
        default:                                   # the tenant id
            siret: '%env(URSSAF_DPAE_SIRET)%'
            nom: '%env(URSSAF_DPAE_NOM)%'
            prenom: '%env(URSSAF_DPAE_PRENOM)%'
            password: '%env(URSSAF_DPAE_PASSWORD)%'   # 8 to 30 characters, sent in clear by URSSAF's design (ADR 0001)
            service: '%env(int:URSSAF_DPAE_SERVICE)%' # 25 direct declarant, 98 concentrator, any other integer is kept
```

Values are built when the service is first used, so every one can be an `%env()%` placeholder. Use Symfony secrets for the
password. The `service` code is any integer: an unknown one is preserved and gets no concentrator port.

### Declaration mode and the simulator guard

A real deposit needs two explicit choices: `declaration_mode: real` **and** `--mode=real` (or `DeclarationMode::Real`) on the
deposit. `gateway.simulated: true` with a literal `declaration_mode: real` is a configuration error; with a placeholder it
is checked when the service is created: `SimulatedGatewayFactory` receives the resolved mode and throws for anything but
the exact value `test`.

## Commands

All commands are namespaced `urssaf:dpae:` (ADR 0009). Tenant options default to the only configured tenant (single-tenant
commands) or to every configured tenant.

| Command | Purpose |
|---|---|
| `urssaf:dpae:check [--tenant] [--skip-remote]` | Reports the declaration mode and the four resolved hosts per tenant, credentials, authentication (a token is requested), token store, schema, lock. Exit 1 on a failure. |
| `urssaf:dpae:deposit <file> --mode=test\|real --reference=<ref> [--tenant] [--dry-run]` | Deposits one XML flow as is. `--mode` is required. `--dry-run` only checks the flow for the mode. Exit 0 only for a receipt. |
| `urssaf:dpae:returns [--tenant] [--from] [--to]` | Lists (and stores) returns; dates are ISO 8601 with an offset; `--from` defaults to now minus `initial_lookback`. Never moves the cursor. The `Ops` column is the value to pass to `download --ops` (`-` when the return has none). |
| `urssaf:dpae:download <flowId> <nature> [--ops] --output=<file> [--force] [--tenant]` | Downloads the document of a listed return into a file (never printed: personal data), created readable by its owner only (`0600`). An existing file is never overwritten without `--force`. |
| `urssaf:dpae:sync-returns [--tenant] [--dry-run]` (alias `urssaf:dpae:sync`) | Synchronises the returns from the cursor. A tenant needing attention fails the command. |
| `urssaf:dpae:reconcile [--tenant] [--dry-run]` | Settles deposits of unknown outcome from the returns; never resends. |
| `urssaf:dpae:relay-outbox [--limit]` | Delivers pending integration events (outbox enabled). |
| `urssaf:dpae:purge-outbox` | Deletes dispatched entries older than `outbox.retention`. |

Deferred (no use case yet): `status`, `resolve`, `requeue`, `download-returns` (ADR 0009 amendment).

## Messenger

With `messenger.enabled: true` three handlers are registered for `Bridge\Symfony\Messenger\DepositDpaeMessage`,
`SynchronizeReturnsMessage` and `ReconcileDepositsMessage`. Payloads are scalar. **The deposit message carries the flow
(base 64), which holds personal data**: protect the transport like your database. Temporary failures (and a refused polling,
after the advised delay) are retried, other package failures are unrecoverable, a held lock is retried in 30 s.

**The failure transport keeps the flow too.** An unrecoverable message, or one whose retries are exhausted, is moved by
Messenger to the `failure_transport` with its whole payload, that is the base-64 flow with the NIR, the names and the
addresses, for as long as nobody removes it. Treat that transport as personal data at rest: give it the same protection and
retention as the database (a dedicated, access-controlled transport, never a shared or world-readable queue); purge it on a
schedule (`messenger:failed:remove`, or a short retention of the storage) and after a failure is handled; and prefer, for the
deposit, a dedicated or synchronous transport (`sync://`, or a transport of the application used for nothing else) over the
transport shared with unrelated messages, so that the flows are not mixed with them nor replayed by a worker that should not
see them. `messenger:failed:retry` resends the message: an unknown outcome is reconciled (`urssaf:dpae:reconcile`), never
resent blindly.

## Locks

Non-blocking Symfony Locks named `urssaf_dpae.<operation>.<scope>` (`deposit.<tenant>.<reference>`, `sync-returns.<tenant>`,
`reconcile.<tenant>`, `relay-outbox.*`, `purge-outbox.*`); the dots and percent signs inside a tenant or a reference are escaped
(`%2E`, `%25`), so two scopes never share a name, and the tenant and the reference are validated before the lock is taken. Commands skip a run whose lock is held; messages are retried.
`auto` enables them when `symfony/lock` and the factory exist. Use a store shared by every host.

## Scheduler

`scheduler.enabled: true` registers the schedule `urssaf_dpae`, consumed with `messenger:consume scheduler_urssaf_dpae`. It
runs the commands above through `RunCommandMessage`. The polling cadence is `frequencies.sync_returns` (no rate limiter).

## Outbox

`outbox.enabled: true` appends the integration events (`urssaf.dpae.*`) in the transaction of each change; relay them with
`urssaf:dpae:relay-outbox` to the `messenger` bus, the `event_dispatcher` or an `IntegrationEventSink` service. At least
once, in order per record; make consumers idempotent.

## Persistence

`doctrine` (default with the ORM; the bundle prepends the types and mapping to `doctrine`, use a dedicated entity manager,
no migration is shipped, see [doctrine.md](doctrine.md)), `in_memory` (lost at the end of the process) or `custom`.

## Token

The token is a credential: it stays in the memory of each process (ADR 0001) and no cache pool is shipped. The name
`urssaf_dpae.token_cache` is reserved for a pool you would back a `token_store.service` with.

## Flex recipe

`recipe/thebeardedbear/urssaf-dpae/0.1/` registers the bundle, adds the `URSSAF_DPAE_*` variables (mode `test`) and a
commented `urssaf_dpae.yaml` with `when@test: gateway.simulated` and a fictitious declarant (the test environment needs no
variable). The credentials of a tenant are built when it is first used: a missing variable breaks that tenant only. `make flex-endpoint` (or
`bin/build-flex-endpoint <dir> <base-url>`) builds a private Flex endpoint; serve it over HTTPS and declare it in
`composer.json` `extra.symfony.endpoint`.

## Coexistence

Commands, variables, locks, schedule, tables, DBAL types, translations (domain `urssaf_dpae`, en / fr) and service ids are
prefixed so that the URSSAF packages install side by side ([ADR 0009](adr/0009-coexistence.md)).
