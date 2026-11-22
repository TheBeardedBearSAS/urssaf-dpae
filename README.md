# URSSAF "API DPAE" for PHP

<!-- the-bearded-bear:badges:start -->
[![CI](https://github.com/TheBeardedBearSAS/urssaf-dpae/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/TheBeardedBearSAS/urssaf-dpae/actions/workflows/ci.yml?query=branch%3Amain) [![Coverage](https://codecov.io/gh/TheBeardedBearSAS/urssaf-dpae/branch/main/graph/badge.svg)](https://app.codecov.io/gh/TheBeardedBearSAS/urssaf-dpae) [![Mutation score](https://img.shields.io/endpoint?style=flat&url=https%3A%2F%2Fbadge-api.stryker-mutator.io%2Fgithub.com%2FTheBeardedBearSAS%2Furssaf-dpae%2Fmain)](https://dashboard.stryker-mutator.io/reports/github.com/TheBeardedBearSAS/urssaf-dpae/main) [![Quality Gate](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-dpae&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=TheBeardedBearSAS_urssaf-dpae) [![Maintainability](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-dpae&metric=sqale_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-dpae) [![Reliability](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-dpae&metric=reliability_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-dpae) [![Security](https://sonarcloud.io/api/project_badges/measure?project=TheBeardedBearSAS_urssaf-dpae&metric=security_rating)](https://sonarcloud.io/summary/overall?id=TheBeardedBearSAS_urssaf-dpae) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/TheBeardedBearSAS/urssaf-dpae/badge)](https://scorecard.dev/viewer/?uri=github.com/TheBeardedBearSAS/urssaf-dpae)
[![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen)](phpstan.neon.dist) [![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)
<!-- the-bearded-bear:badges:end -->

`thebeardedbear/urssaf-dpae` — client, domain model and Symfony integration for the URSSAF API that lets software
producing *Déclarations Préalables À l'Embauche* (DPAE) deposit declarations in volume and retrieve the electronic
receipts (AEE), conformity certificates and business reports (*accusé de réception* de la DPAE).

Namespace: `TheBeardedBear\UrssafDpae`.

Developed and maintained by [The Bearded Bear](https://the-bearded-bear.com).

> [!IMPORTANT]
> **Unofficial package.** This project is an independent open-source implementation of a public API. It is not
> developed, endorsed, certified or supported by URSSAF, and it is not affiliated with URSSAF in any way. "URSSAF" is
> used only to name the API this package talks to. Using this package does not replace the URSSAF habilitation
> process; the URSSAF documentation remains the authority on the API, and you remain responsible for the data your
> application sends to URSSAF.

> **Status: implemented.** The domain and declaration model, the HTTP gateway, the application layer (deposit, return
> synchronisation, download, reconciliation of a lost answer, outbox), the Doctrine bridge and the Symfony bundle are
> implemented and covered by the quality gate below. The first release waits for the contract tests to run against the live
> hosts: the points that only URSSAF can settle (hosts, test indicator, authentication XML, bodies of the returns) are
> listed as open in [docs/api-reference.md](docs/api-reference.md), each one configurable meanwhile. What is deferred is in
> [docs/roadmap.md](docs/roadmap.md).

## Scope

| Operation | Endpoint |
|---|---|
| Authenticate (XML `identifiants`, returns a base-64 token) | `POST /authentifier_dpae` |
| Deposit one DPAE flow (gzip) | `POST /deposer-dsn/1.0` |
| List the returns from a date | `GET /lister-retours-declarant/1.0/{dateDebut}` |
| List the returns of a date range | `GET /lister-retours-declarant/1.0/{dateDebut}/{dateFin}` |
| Download a return (AEE, certificate, report) | `GET /telecharger-retour/1.0/{idFlux}/{idRetour}` |

Not OAuth2: token authentication (`Authorization: DPAELogin jeton=…`) with the credentials of an *Urssaf en ligne*
subscriber. XML payloads. Production only: test vs real is selected by the test indicator of the deposited flow. Hosts
(Swagger `https://api.urssaf.fr/authentifier_dpae` versus dedicated hosts in the PDF guide) are an open point, see the
[reference](docs/api-reference.md).

## HTTP client

The gateway takes any PSR-18 client with PSR-17 factories. **Disable the redirections of the client** (for example
Symfony HttpClient `max_redirects => 0`): a 307 / 308 would make the client resend the deposit `POST`, and the package
never resends a deposit (a 3xx answer is reported as an unexpected response). A base URI may use `http` only for a mock
server, never for a published URSSAF host. A real declaration needs `ApiConfiguration::withDeclarationMode(DeclarationMode::Real)`
(default `Test`) and a flow of mode `Real`.

## Quick start (standalone)

No framework needed: any PSR-18 client with PSR-17 factories, and a credentials provider. Everything below runs in
**test mode** (`ApiConfiguration::defaults()` caps the installation at `DeclarationMode::Test`; a `real` flow is refused
before any call).

```php
use TheBeardedBear\UrssafDpae\Application\Command\DepositDpae;
use TheBeardedBear\UrssafDpae\Application\Command\ReconcileUnknownDeposits;
use TheBeardedBear\UrssafDpae\Application\Command\SynchronizeReturns;
use TheBeardedBear\UrssafDpae\Domain\Declarant\DeclarantCredentials;
use TheBeardedBear\UrssafDpae\Domain\Declarant\ServiceCode;
use TheBeardedBear\UrssafDpae\Domain\Declarant\Siret;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DeclarationMode;
use TheBeardedBear\UrssafDpae\Domain\Declaration\DpaeFlow;
use TheBeardedBear\UrssafDpae\Domain\Declaration\TestIndicators;
use TheBeardedBear\UrssafDpae\Domain\Deposit\ExternalReference;
use TheBeardedBear\UrssafDpae\Domain\Tenant\TenantId;
use TheBeardedBear\UrssafDpae\Infrastructure\Credentials\InMemoryCredentialsProvider;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\ApiConfiguration;
use TheBeardedBear\UrssafDpae\Infrastructure\Urssaf\HttpTransport;
use TheBeardedBear\UrssafDpae\Standalone\UrssafDpaeBuilder;

// $client: a PSR-18 client with redirections disabled; $factory: a PSR-17 request and stream factory.
$tenant = TenantId::fromString('my-company');
$credentials = new InMemoryCredentialsProvider([
    'my-company' => DeclarantCredentials::of(Siret::fromString($siret), $name, $firstName, $password, ServiceCode::direct()),
]);
$dpae = UrssafDpaeBuilder::create(new HttpTransport($client, $factory, $factory), $credentials, ApiConfiguration::defaults())->build();

// Deposit one flow. The flow carries its own mode; the reference is your internal identifier of the hire (idempotence key):
// never a NIR, a name or any other personal data, it is stored, relayed in events and logged.
$flow = DpaeFlow::fromXml($xml, DeclarationMode::Test, TestIndicators::defaults());
$deposit = $dpae->depositDpae()(new DepositDpae($tenant, ExternalReference::fromString('hire-42'), $flow));
$deposit->state(); // received | rejected | outcome_unknown | failed | invalid_credentials, or depositing while another worker owns the send
// (a flow that provably did not reach URSSAF leaves the deposit pending and rethrows the failure: call again to retry)

// From a cron: pick up the returns (receipts, certificates), and settle the deposits whose answer was lost.
$dpae->synchronizeReturns()(new SynchronizeReturns($tenant));
$dpae->reconcileUnknownDeposits()(new ReconcileUnknownDeposits($tenant));
```

A deposit is **never resent** once it may have reached URSSAF: a lost answer leaves it `outcome_unknown` until the
reconciliation finds the flux in the return list. The defaults keep everything in memory (`Standalone\Persistence`): the
full lifecycle needs durable repositories, the Doctrine bridge ([ADR 0008](docs/adr/0008-doctrine-persistence.md),
[docs/doctrine.md](docs/doctrine.md): optional `doctrine/orm` 3 and `doctrine/dbal` 4, tables prefixed `urssaf_dpae_`, no
migration shipped, retention and encryption of the personal data are yours): build a `Persistence` from the
`Doctrine*` repositories of one entity manager and give it to `UrssafDpaeBuilder::withPersistence()`. In
your tests, `UrssafDpaeBuilder::withGateway()` takes a `Testing\SimulatedDpaeGateway`, refused in a `real` installation.

## Symfony

```sh
composer require thebeardedbear/urssaf-dpae symfony/http-client symfony/lock symfony/messenger symfony/scheduler
```

```yaml
# config/packages/urssaf_dpae.yaml (copied by the Flex recipe in recipe/)
urssaf_dpae:
    declaration_mode: '%env(URSSAF_DPAE_DECLARATION_MODE)%'   # test by default, never real by default
    credentials:
        default: { siret: '%env(URSSAF_DPAE_SIRET)%', nom: '%env(URSSAF_DPAE_NOM)%', prenom: '%env(URSSAF_DPAE_PRENOM)%', password: '%env(URSSAF_DPAE_PASSWORD)%', service: '%env(int:URSSAF_DPAE_SERVICE)%' }
```

```sh
bin/console urssaf:dpae:check                                            # configuration, hosts, credentials, schema
bin/console urssaf:dpae:deposit flow.xml --mode=test --reference=hire-42  # deposit one flow, the mode is never implied
bin/console urssaf:dpae:sync-returns                                     # from a cron or the Scheduler: pick up the returns
bin/console urssaf:dpae:reconcile                                        # settle the deposits whose answer was lost
```

Console commands `urssaf:dpae:check`, `deposit`, `returns`, `download`, `sync-returns`, `reconcile`, `relay-outbox` and
`purge-outbox`, Messenger handlers, locks, a Scheduler schedule and a Flex recipe, all prefixed so that the URSSAF
packages coexist. Durable persistence needs the Doctrine bridge. Details: [docs/symfony.md](docs/symfony.md),
[docs/doctrine.md](docs/doctrine.md).

## Documentation

- [docs/api-reference.md](docs/api-reference.md) — technical reference (single source of truth for the package).
- [docs/symfony.md](docs/symfony.md) — Symfony bundle: configuration, commands, Messenger, locks, Scheduler, Flex recipe.
- [docs/doctrine.md](docs/doctrine.md) — Doctrine persistence: schema, concurrency, transactions, personal data (GDPR).
- [docs/workflow.md](docs/workflow.md) — deposit and return state machines, idempotence, retry, reconciliation, outbox, polling.
- [docs/testing.md](docs/testing.md) — test suites, fakes, the simulated gateway, contract tests, coverage, mutation, benchmarks.
- [docs/roadmap.md](docs/roadmap.md) — architecture and order of work, with the lots delivered.
- [docs/openapi/](docs/openapi/) — official OpenAPI contract(s) from the URSSAF API portal.
- `docs/reference/` — official URSSAF documentation: not versioned (URSSAF documents), download it from the links in [docs/api-reference.md](docs/api-reference.md) into this local, git-ignored folder.
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/dpae

## Requirements

PHP 8.2+. Development only needs Docker: every `make` target runs in a container.

## Development

```sh
make install     # build the image, install dependencies
make ci          # full quality gate: cs, rector, phpstan, mago, deptrac, composer checks, hadolint, guard, coverage, infection
make test        # unit + functional tests
make bench       # benchmarks (PHPBench, tests/Benchmark)
make test-contract  # live contract tests, test mode only, skipped without the URSSAF_DPAE_* variables
make help        # every target
```

`make ci PHP_VERSION=8.4` runs the gate on another PHP version. The contract tests (`tests/Contract`) talk to the live
URSSAF hosts and are skipped unless `URSSAF_DPAE_SIRET`, `URSSAF_DPAE_NOM`, `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD` and
`URSSAF_DPAE_SERVICE` are set (optional: the four host overrides `URSSAF_DPAE_{AUTHENTICATION,DEPOSIT,CONSULTATION,DOWNLOAD}_URI`,
and `URSSAF_DPAE_CONTRACT_DEPOSIT=1` for the one test that writes). They always run in test mode, see
[docs/testing.md](docs/testing.md).

Once the repository is public, `main` publishes its quality proofs, linked from the badges at the top of this file: the
coverage report on Codecov, the full mutation report on the Stryker Dashboard, the SonarQube Cloud quality gate and the
OpenSSF Scorecard. The benchmarks (`tests/Benchmark`, PHPBench in `tools/phpbench`, out of the package dependencies)
run on every pull request against the base commit, and the job fails on a regression of more than 20 %.

## Versioning

Semantic Versioning. `SpecVersion::CURRENT` holds the version of the official URSSAF documentation implemented.

<!-- the-bearded-bear:start -->
## Other URSSAF packages

The same architecture, quality gate and conventions, one package per URSSAF API (overview: [the-bearded-bear.com/open-source/urssaf](https://the-bearded-bear.com/open-source/urssaf)):

| API | Package | Status |
|---|---|---|
| Tiers de prestations (immediate tax-credit advance) | [`thebeardedbear/urssaf-tiers-prestations`](https://github.com/TheBeardedBearSAS/urssaf-tiers-prestations) | Released (Packagist) |
| Attestations Vérification Code Sécurité (certificate verification) | [`thebeardedbear/urssaf-attestations-verification-code-securite`](https://github.com/TheBeardedBearSAS/urssaf-attestations-verification-code-securite) | Released (Packagist) |
| Mobilité internationale (posting of workers abroad) | [`thebeardedbear/urssaf-mobilite-internationale`](https://github.com/TheBeardedBearSAS/urssaf-mobilite-internationale) | Coming soon |
| Plateforme numérique (withholding of contributions by digital platforms) | [`thebeardedbear/urssaf-plateforme-numerique`](https://github.com/TheBeardedBearSAS/urssaf-plateforme-numerique) | Released (Packagist) |
| Tierce Déclaration auto-entrepreneur | [`thebeardedbear/urssaf-tierce-declaration-auto-entrepreneur`](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-auto-entrepreneur) | Released (Packagist) |
| Tierce Déclaration CESU | [`thebeardedbear/urssaf-tierce-declaration-cesu`](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-cesu) | Released (Packagist) |
| Tierce Déclaration PAJE | [`thebeardedbear/urssaf-tierce-declaration-paje`](https://github.com/TheBeardedBearSAS/urssaf-tierce-declaration-paje) | Released (Packagist) |

## Professional support

This package is developed and maintained by [The Bearded Bear](https://the-bearded-bear.com).
If you need help with an URSSAF integration, The Bearded Bear can help with:

- the URSSAF habilitation and homologation process (subscription, sandbox tests, move to production);
- integrating the package into your application (Symfony, Doctrine or framework-free);
- support with a guaranteed response time, including the follow-up of URSSAF API changes;
- custom developments on top of the package.

Project page and integration offer: [the-bearded-bear.com/open-source/urssaf-dpae](https://the-bearded-bear.com/open-source/urssaf-dpae).
<!-- the-bearded-bear:end -->

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## License

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

The API specification summarised in [docs/api-reference.md](docs/api-reference.md) belongs to URSSAF; see the sources
listed there.
