# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
  `URSSAF_DPAE_PRENOM`, `URSSAF_DPAE_PASSWORD`, `URSSAF_DPAE_SERVICE`, optional `URSSAF_DPAE_BASE_URI`).
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
