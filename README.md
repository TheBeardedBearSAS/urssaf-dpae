# URSSAF "API DPAE" for PHP

`thebeardedbear/urssaf-dpae` — client, domain model and Symfony integration for the URSSAF API that lets software
producing *Déclarations Préalables À l'Embauche* (DPAE) deposit declarations in volume and retrieve the electronic
receipts (AEE), conformity certificates and business reports (*accusé de réception* de la DPAE).

Namespace: `TheBeardedBear\UrssafDpae`.

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
- [docs/reference/](docs/reference/) — official URSSAF documentation.
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

## License

Proprietary — see [LICENSE](LICENSE).
