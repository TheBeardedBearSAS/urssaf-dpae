# Testing

Everything runs in Docker (`make help` lists the targets). The tests of `make ci` make no network call and use no real
data: every fixture is fictitious.

## Suites

| Suite | Directory | Command | Content |
|---|---|---|---|
| unit | `tests/Unit` | `make test-unit` | Domain, wire readers and writers, HTTP client, handlers, simulator, standalone builder, bundle classes |
| functional | `tests/Functional` | `make test-functional` | Symfony kernel wiring, Messenger, commands, Flex recipe, Doctrine on SQLite (repository contracts) |
| contract | `tests/Contract` | `make test-contract` | Live checks against the URSSAF hosts, test mode only, opt-in (below) |

`make test` runs unit and functional. The fixtures of the wire formats are in `tests/Fixtures/wire`: the declaration
writer's golden file is the official example of the specification, the answers (`envoi_*.xml`, `retours_*.xml`) are
fictitious and inferred because URSSAF publishes no example of them (api-reference.md sections 5.9, 5.18 and 5.19). They are
replaced by anonymised captures once the contract tests have produced samples.

## Fakes and doubles

The handlers are tested through ports, never through a mocking library:

- `tests/Unit/Application/Fake/FakeDpaeGateway`: a scripted gateway, each call consumes the next scripted answer (a value
  or a throwable) and is recorded. It makes every branch of the use cases reachable: a receipt, a rejection notice, a
  lost answer (`OutcomeUnknown`), a failure that proves nothing was sent.
- `Fake\Harness` wires the handlers over the in-memory adapters (`Infrastructure\Persistence\InMemory\*`, a fixed clock, a
  sequential id generator) and `Fake\Racing*` / `Fake\LosingClaim*` repositories reproduce the concurrent claim and the
  optimistic-lock races deterministically.
- Recording doubles for the event publisher, the failure reporter, the integration event sink and the transaction manager.

The in-memory and the Doctrine repositories pass the same repository contract traits (`tests/Functional/Doctrine/Contract`),
so an adapter of your own can be checked the same way.

## SimulatedDpaeGateway

`Testing\SimulatedDpaeGateway` is the double for the tests of **your** application. It implements `DpaeGateway`, records
each deposited flow as a flux, answers a receipt and lists and serves the nature `10` return of each flux. Scripting:

```php
use TheBeardedBear\UrssafDpae\Application\Port\Operation;
use TheBeardedBear\UrssafDpae\Testing\SimulatedDpaeGateway;

$gateway->rejectNextDeposit(['motif 1']);          // the next deposit is answered with a rejection notice
$gateway->failNext(Operation::Deposit, $failure);   // the next call of the operation throws
$gateway->loseNextAnswer();                         // recorded by "URSSAF", answer lost: the reconciliation finds the flux
$gateway->purge($flowId);                           // the return is gone, as after three months: download is a 404
$gateway->calls(); $gateway->deposited();           // what was received
```

Limits, on purpose: it **refuses a `Real` flow** with a `LogicException` whatever the wiring, it never produces the
returns `20` and `41` (they exist in real mode only), and its documents are fictitious: do not parse them. Plug it with
`UrssafDpaeBuilder::withGateway()` (refused in a `real` installation) or, in Symfony, `urssaf_dpae.gateway.simulated: true`
(the container wiring accepts only the exact mode `test`).

## Contract tests

`tests/Contract` talks to the live hosts. It is **skipped** unless these variables are set:

| Variable | Required | Meaning |
|---|---|---|
| `URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`, `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD` | yes | Credentials of an *Urssaf en ligne* subscriber |
| `URSSAF_DPAE_SERVICE` | yes | Service code, an integer (`25` direct, `98` registered concentrator) |
| `URSSAF_DPAE_AUTHENTICATION_URI`, `URSSAF_DPAE_DEPOSIT_URI`, `URSSAF_DPAE_CONSULTATION_URI`, `URSSAF_DPAE_DOWNLOAD_URI` | no | Host overrides (ADR 0002, 0009); the published hosts by default |
| `URSSAF_DPAE_CONTRACT_DEPOSIT` | no | `1` allows the one call that writes (a test-mode deposit of the fictitious official example) |

```sh
export URSSAF_DPAE_SIRET=... URSSAF_DPAE_NOM=... URSSAF_DPAE_PRENOM=... URSSAF_DPAE_PASSWORD=... URSSAF_DPAE_SERVICE=25
make test-contract
```

The suite is **never in real mode**: the gateway is built from `ApiConfiguration::defaults()` (installation ceiling
`DeclarationMode::Test`) and the only flow it builds carries the test indicator; a test checks that a `real` flow is
refused before any call. It is not part of coverage, mutation or `make ci`; in CI it is the manual `contract` job
(Actions, Run workflow, `contract`, with the secrets `URSSAF_DPAE_SIRET`, `_NOM`, `_PRENOM`, `_PASSWORD`, `_SERVICE` and
the optional repository variables for the four hosts and `URSSAF_DPAE_CONTRACT_DEPOSIT`).

First objectives (each closes an open point of api-reference.md section 5):

1. the hosts and paths that are live (section 5.1): the Swagger `api.urssaf.fr` or the four hosts of the PDF, with or
   without the concentrator port;
2. whether the test indicator `TST` is the value URSSAF expects and whether a test deposit has side effects (section 5.2);
3. the shape of the authentication XML (section 5.4), accepted or refused with a `WWW-Authenticate` realm;
4. whether a built flow without raison sociale is accepted (section 5.12);
5. the date formats and zone of the list paths (section 5.8);
6. samples of the AEE, the rejection notice, the list answer and the returns (section 5.9).

Samples are written by `Contract\SampleRecorder` to `var/contract-samples` (git-ignored, mode `0600`): the answers of the
deposit, list and download calls, never the authentication exchange. They may hold personal data: do not commit them,
anonymise them by hand before they become a fixture.

## Coverage

- `make coverage`: pcov over `src`, unit and functional, **100 %** of lines, methods and classes (`bin/check-coverage`).
- `make coverage-branches`: Xdebug branch and path coverage, **at least 96 %** of branches (`bin/check-branch-coverage`,
  Cobertura in `var/coverage`). Slow: it runs on `main`, nightly and on demand in CI (job `coverage-branches`), never on
  a pull request. The threshold is never lowered: add a test instead.
- `make coverage-paths`: path coverage HTML report in `var/coverage/paths`.

## Mutation testing

`make infection` runs Infection over `src` with the unit and functional suites and requires **100 % MSI and 100 % covered
MSI**. It runs its own initial test pass (never `--skip-initial-tests`, which yields false positives) and fails when a
mutant is skipped, timed out or has a syntax error: every mutant really ran and was killed (`infection.json5`). A mutant
that survives is a missing assertion: strengthen the test, do not widen an ignore list.

## Benchmarks

`make bench` runs PHPBench (`tools/phpbench`, kept out of the package dependencies) over `tests/Benchmark`: the validation
of a declaration, the encoding of a deposit (flow builder, raw-flow check, gzip) and the decoding of the answers (receipt,
rejection notice, listings up to a large synthetic one). Revolutions, iterations and the report are set in
`phpbench.json`, the benchmarks carry no attribute. On a pull request the CI job also runs them against the base commit
and fails on a regression of more than 20 %.

## Other gates

`make ci` chains `cs`, `rector`, `phpstan` (level max), `mago`, `deptrac` (layers), `composer-checks`, `hadolint`, `guard`
(forbidden word, SPDX headers), `coverage` and `infection`. `make ci PHP_VERSION=8.4` runs it on another PHP version; the
CI matrix also covers 8.2 to 8.4 with the lowest and highest dependencies, and Symfony 8.
