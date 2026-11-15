# URSSAF "API DPAE" for PHP

`thebeardedbear/urssaf-dpae` — client, domain model and Symfony integration for the URSSAF API that lets software
producing *Déclarations Préalables À l'Embauche* (DPAE) deposit declarations in volume and retrieve the electronic
receipts (AEE), conformity certificates and business reports (*accusé de réception* de la DPAE).

Namespace: `TheBeardedBear\UrssafDpae`.

> [!IMPORTANT]
> **Unofficial package.** This project is an independent open-source implementation of a public API. It is not
> developed, endorsed, certified or supported by URSSAF, and it is not affiliated with URSSAF in any way. "URSSAF" is
> used only to name the API this package talks to. Using this package does not replace the URSSAF habilitation
> process; the URSSAF documentation remains the authority on the API, and you remain responsible for the data your
> application sends to URSSAF.

> 🚧 **Skeleton — not implemented yet.** This repository currently contains the tooling and the specification only.
> The implementation follows [docs/roadmap.md](docs/roadmap.md).

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

## Documentation

- [docs/api-reference.md](docs/api-reference.md) — technical reference (single source of truth for the package).
- [docs/roadmap.md](docs/roadmap.md) — planned architecture and order of work.
- [docs/openapi/](docs/openapi/) — official OpenAPI contract(s) from the URSSAF API portal.
- `docs/reference/` — official URSSAF documentation: not versioned (URSSAF documents), download it from the links in [docs/api-reference.md](docs/api-reference.md) into this local, git-ignored folder.
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/dpae

## Requirements

PHP 8.2+. Development only needs Docker: every `make` target runs in a container.

## Development

```sh
make install     # build the image, install dependencies
make ci          # full quality gate: cs, rector, phpstan, mago, deptrac, composer checks, guard, coverage, infection
make test        # unit + functional tests
make help        # every target
```

`make ci PHP_VERSION=8.4` runs the gate on another PHP version.

## Versioning

Semantic Versioning. `SpecVersion::CURRENT` holds the version of the official URSSAF documentation implemented.

## Contributing and security

Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report vulnerabilities privately as described in
[SECURITY.md](SECURITY.md), never in a public issue.

## License

MIT. See [LICENSE](LICENSE). The software is provided "as is", without warranty of any kind.

The API specification summarised in [docs/api-reference.md](docs/api-reference.md) belongs to URSSAF; see the sources
listed there.
