# Roadmap

Intended direction for the next twelve months (November 2026 to November 2027). It is a statement of intent, not a
commitment; it is reviewed at each minor release. Proposals are welcome in GitHub issues.

## Planned

- **Implement the package.** The repository currently holds the tooling and the specification; the order of work is in
  [docs/roadmap.md](docs/roadmap.md): authentication (XML `identifiants`, opaque token), deposit of a gzip-compressed
  DPAE flow, listing and download of the returns (AEE, conformity certificate, business report), then the Symfony and
  Doctrine integrations.
- **Release 0.1, then 1.0.** Publish a first `0.x` once the deposit and return cycle works end to end, stabilise the
  public API (domain model, client, Symfony bundle, Doctrine integration), then release `1.0.0`. From 1.0 on, breaking
  changes only happen in major versions, as required by [Semantic Versioning](https://semver.org/).
- **Follow the URSSAF API.** Track each new version of the URSSAF *API DPAE* (Swagger and the NAT/5491, NAT/5492 and
  NAT/5493 documents), settle the open points listed in [docs/api-reference.md](docs/api-reference.md) (the four hosts,
  XML element names) as the official documentation or the URSSAF support answers, and release the matching changes,
  with upgrade notes in the [changelog](CHANGELOG.md).
- **Keep up with the platform.** Support new PHP versions (8.5 and later) and the new Symfony and Doctrine versions
  as they are released, and drop the versions that reach their end of life in a minor release, announced in the
  changelog.
- **Keep the quality and security bar.** 100 % coverage and 100 % MSI, PHPStan at level max, signed releases, and the
  OpenSSF Best Practices criteria.

## Not planned

- Other URSSAF APIs: each one gets its own package (see *Other URSSAF packages* in the [README](README.md)).
- The DSN "API" mode and the flat-format DPAE layout, as long as URSSAF does not publish their specification.
- Official bridges for other frameworks than Symfony: the core is meant to work without a framework.
