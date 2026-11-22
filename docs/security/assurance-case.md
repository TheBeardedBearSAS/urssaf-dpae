# Security assurance case

This document argues why the security requirements of `thebeardedbear/urssaf-dpae` are met, and states its limits.
**Status: the package is a specified skeleton.** The implementation is not written yet (see
[docs/roadmap.md](../roadmap.md)), so this case records the design commitments, taken from the technical reference
([api-reference.md](../api-reference.md)) and the [reference architecture ADR](../adr/0000-reference-architecture.md),
that the implementation must satisfy and that the quality gate must verify. Each claim marked *to implement* is
re-checked against the code, with file references, when the corresponding code lands. The package is a library, so
part of the security of a deployment belongs to the host application (see
[section 7](#7-assumptions-on-the-host-application)).

Reporting a vulnerability: [SECURITY.md](../../SECURITY.md).

## 1. What is protected

The package lets DPAE-producing software authenticate on the URSSAF DPAE deposit point, deposit *Déclarations
Préalables À l'Embauche* and download the returns (AEE, conformity certificates, business reports).

| Asset | Where it lives | Sensitivity |
|---|---|---|
| Credentials of an *Urssaf en ligne* subscriber (SIRET, name, first name, password, service code) | Host configuration or a credentials provider; in memory during the authentication call | Secret |
| Session token (opaque, base 64) | In memory for the duration of a session, sent as `Authorization: DPAELogin jeton=…` | Secret (lifetime not published) |
| Personal data of the hired employee and of the employer: NIR (social security number), surname, first names, date and place of birth, address | Domain objects, the deposited XML flow, host database (Doctrine mapping), returns kept after the URSSAF purge | Personal data (GDPR), high |
| Deposited flows and returns (AEE, certificates, reports, PDF) | Host database or file storage | Integrity critical (legal obligation to declare) |
| Source code and releases | GitHub repository, Packagist, release assets | Integrity critical (supply chain) |

## 2. Security requirements

| ID | Requirement |
|---|---|
| SR1 | The password and the token are never persisted by the package, never logged, never present in exception messages, and masked when dumped. |
| SR2 | The NIR, names, birth date and place, address and every other personal datum are never logged by the package and never present in exception messages; they are erasable (GDPR). |
| SR3 | The flow deposited with URSSAF is what the host asked for: validated before sending, encoded exactly (ISO-8859-1, gzip), and not silently duplicated by retries. |
| SR4 | Everything received from URSSAF is untrusted: XML parsed without external entities or network access, error pages never interpreted, and a malformed response fails closed. |
| SR5 | Requests are authenticated and sent to the intended URSSAF hosts only, over HTTPS; one tenant can neither use nor see another tenant's credentials, token or records. |
| SR6 | A failure of URSSAF or of the network degrades gracefully: bounded retries, an explicit "may have reached URSSAF" signal for a deposit, no unbounded loop, polling within the URSSAF limits (HTTP 422 / 429). |
| SR7 | The code and the release artefacts come from the maintainers' reviewed and tested commits and can be verified by consumers. |

## 3. Threat model

| Actor | Capability considered | Main threats |
|---|---|---|
| Network attacker | Observes or tampers with traffic between the host and URSSAF | Capture of the password sent in the authentication body, of the token, or of the NIR in a deposit; response tampering; downgrade |
| Malicious or compromised endpoint (or a proxy configured as such) | Returns arbitrary bodies, statuses, sizes and content types | XML external entity (XXE) and entity expansion, resource exhaustion (a return can be a large XML or PDF), secrets in error text |
| Host application bug or misconfiguration | Controls configuration, logger, cache, database | Password in clear configuration, `http://` host override, verbose logs of bodies, no retention policy on the NIR |
| Other tenant of a multi-tenant host | Uses the same host application | Cross-tenant access to records, tokens or credentials |
| Hostile user-supplied data | Names, addresses, NIR reach the package through the host | XML injection in the deposited flow, malformed values rejected by URSSAF |
| Malicious contributor, poisoned dependency, abused CI token | Pull request, dependency update, workflow permissions | Backdoor in the package, secret exfiltration from CI, tampered release |

Out of scope: compromise of the host server or database, and the security of the URSSAF platform (see
[SECURITY.md](../../SECURITY.md#scope)).

## 4. Trust boundaries

| Boundary | What crosses | Control (to implement unless stated) |
|---|---|---|
| B1 host to package | Commands, raw values, credentials | Value objects validate at construction (anchored regexes with `/D`, length and range limits, NIR key check); credentials only through a provider, marked `#[SensitiveParameter]` |
| B2 package to URSSAF | HTTPS requests to **four hosts** (authentication, deposit, consultation, download; port `8443` for concentrators); untrusted responses | Hosts and paths are constants or operator configuration; the only URL read from a response, the `url` of a return, is honoured only if its host matches the configured download host (*to settle* in ADR 0002); TLS and certificate verification delegated to the PSR-18 client; the package never disables verification |
| B3 package to database | Personal data and returns at rest | Doctrine bridge (lot 7): DBAL named parameters, custom column types the host can replace to encrypt (`urssaf_dpae_bytes`, `urssaf_dpae_deposit_result`), retention purges (`deleteFinishedBefore()`, `deleteSeenBefore()`, `deleteDownloadedBefore()`, `Outbox::purgeDispatchedBefore()`) and erasure by key (`DoctrineDepositRepository::delete()` / `deleteByReference()`, `DoctrineDepositFlowStore::delete()`), documented in [docs/doctrine.md](../doctrine.md). No anonymisation is provided; the SHA-256 fingerprint is pseudonymised data, not anonymous |
| B4 package to memory | Token | Kept in memory only (no cache pool in the default design), see SR1 |
| B5 package to logs | Technical metadata | Fixed set of fields: method, host, path, status, duration, exception class; no body, no header value |
| B6 pipeline to consumers | Releases | Pinned actions, least-privilege tokens, SLSA provenance, checksums (in place, see `.github/workflows/`) |

Inside the package, Deptrac enforces the layers that separate these zones (`deptrac.yaml`, *to complete*: the Contract, Testing and Standalone layers are missing from `deptrac.yaml`).

## 5. Secure design principles

*To implement:* these principles are design commitments; none is verified against code yet.

| Principle | How it is applied |
|---|---|
| Economy of mechanism | No third-party runtime code: `require` lists only `php`, PHP extensions and PSR interfaces. The HTTP client and logger come from the host. |
| Fail-safe defaults | HTTPS only; the default logger is a `NullLogger`; an unexpected status, content type or body raises `UnexpectedUrssafResponse` instead of being interpreted. |
| Complete mediation | Every URSSAF call goes through one gateway that adds the `DPAELogin` authorization, maps the status and checks the content type. Value objects have private constructors, so every instance went through validation. |
| Open design | Open source, documented protocol mapping ([api-reference.md](../api-reference.md)), public quality thresholds. |
| Separation of privilege | Strict layers: Domain depends on nothing, Application on Domain and Contract, Infrastructure and bridges on the layers below (`deptrac.yaml`). |
| Least privilege | Credentials supplied per tenant on demand; CI workflows default to `contents: read` and elevate per job. |
| Defence in depth | Secrets and personal data are masked in `__debugInfo()`, not serialisable, not logged, absent from exception messages. |
| Fail securely | HTTP errors become typed exceptions carrying the status; a deposit that may have reached URSSAF is signalled explicitly (`mayHaveReachedUrssaf()`) so the host can reconcile instead of blindly resending. |

## 6. DPAE-specific weaknesses and countermeasures

| Weakness (OWASP Top 10 2021 / CWE) | Countermeasure | Status |
|---|---|---|
| **Password sent in clear** (A02, CWE-319). The API requires the `motdepasse` element in the XML `identifiants` body of every authentication ("transmis en clair", [api-reference §2.1](../api-reference.md)); there is no OAuth2 and no alternative. | The password only travels over TLS (HTTPS hosts only, certificate verification never disabled). It is built into the request body at the last moment, never logged or put in an exception, marked `#[SensitiveParameter]`, masked by `__debugInfo()`, and the credentials object refuses serialisation. The host must store it encrypted, as URSSAF itself requires for payroll software. The request body is never passed to the logger. | To implement; residual risk accepted (protocol imposed by URSSAF) |
| **Opaque token** (A07, CWE-522). The token is a base-64 string with unpublished lifetime and format. | Treated as an opaque secret: kept in memory only, never persisted or logged, never put in a URL, masked when dumped, renewed on a 401 `Expiration du jeton` (one replay at most), and a 401 `Jeton manquant ou invalide` is not retried in a loop. | To implement |
| **Personal data in logs and messages** (A09, CWE-532, CWE-200). NIR, surname, first names and birth data are processed. | Logs hold method, host, path, status, duration and exception class only. Exception messages hold violation codes and statuses, never raw values (`<subject>.<reason>` codes). Value objects holding personal data have no `__toString()` and are masked in dumps. Unit tests assert that no personal datum appears in a message or a log record. | To implement |
| **XXE and entity expansion** (A05, CWE-611, CWE-776). Returns and answers are XML from a remote party. | XML is parsed with `LIBXML_NONET`, no `LIBXML_DTDLOAD`, `LIBXML_DTDATTR` or `LIBXML_NOENT`; a document carrying a `DOCTYPE` is rejected; `libxml_disable_entity_loader` is not relied upon (deprecated since PHP 8.0, entity loading is off by default in libxml 2.9+). The same rule applies to any XSD validation: schemas are local files, never fetched. | To implement, with a unit test on a hostile document |
| **XML injection in the deposited flow** (CWE-91, CWE-116). Names and addresses are interpolated in the DPAE XML. | The flow is built through `DOMDocument` / `XMLWriter` (escaping by the library), never by string concatenation; characters that ISO-8859-1 cannot represent are rejected at validation instead of being replaced. | To implement |
| **Charset and compression** (CWE-838, CWE-409). The deposit body is a gzip-compressed flow in ISO-8859-1. | Encoding is explicit (the declaration and the actual bytes agree, checked by a test); compression uses `gzencode` on the whole flow; an optional, configurable maximum payload size is enforced before sending (URSSAF publishes no limit, [api-reference §4.7, §5.16](../api-reference.md)); received gzip bodies are decoded with a size cap (decompression bomb). | To implement |
| **Error pages not interpreted** (CWE-209). Error bodies are Apache HTML pages (401, 404, 422). | The HTML body is ignored: the exception is built from the status code and the `WWW-Authenticate` realm only, never from the body, and the body is never copied into a message or a log. Only the deposit `422` carries a rejection notice (XML), which goes through the strict XML parser above. | To implement |
| **SSRF and host confusion** (A10, CWE-918). Four hosts, with port `8443` for concentrators. | The four base URIs are operator configuration with constant paths, validated as absolute URLs at configuration time (plain `http://` allowed only for a local mock, never against the production hosts); the `<url>` tag of the return lists is parsed as data and followed only if its origin (scheme, host, port) matches the configured download base URI ([ADR 0002](../adr/0002-hosts-per-service.md)). | To implement |
| **Replay and duplicate declarations** (A04). The API has no "annule et remplace"; duplicates are handled by URSSAF business control (code `98`). | A deposit is not retried automatically after a timeout; the `mayHaveReachedUrssaf()` signal lets the host reconcile through the return list. | To implement |
| **Return purge** (A04). Returns are purged by URSSAF after 3 months. | The Doctrine bridge (lot 7) stores returns and their documents so the legal proof outlives the purge, with no local retention period (the host's purge jobs delete them); a missing return (404), or a downloaded return whose stored document was purged, is a typed "gone" outcome, not a generic failure. | Delivered (lot 7), functional tests on SQLite |
| Insecure deserialisation (A08, CWE-502) | `unserialize` is never used on data from URSSAF; XML is read into typed value objects. | By design, checked by review and PHPStan |
| Hard-coded credentials (CWE-798) | None in the repository; fixtures use fictitious values; the contributing rules forbid real data; GitHub secret scanning with push protection. | In place |
| Vulnerable and outdated components (A06) | No third-party runtime code; `composer audit`, `roave/security-advisories`, Dependabot, committed `composer.lock`. | In place |
| Supply chain, integrity failures (A08) | Actions pinned to a commit SHA, `contents: read` by default, CodeQL on workflows, OpenSSF Scorecard, releases with `SHA256SUMS` and a SLSA provenance attestation, branch protection on `main`. | In place |

### Tools that verify these countermeasures

| Tool | What it checks | Where it runs |
|---|---|---|
| PHPStan level max (strict, deprecation, Doctrine, Symfony, PHPUnit rules) | Type safety, unsafe patterns | CI, `make phpstan` |
| Mago lint and analyze | Static analysis | CI, `make mago` |
| Deptrac | Layer boundaries inside the package | CI, `make deptrac` |
| PHPUnit with the coverage thresholds (lines and branches) | Behaviour including failure paths | CI, `make coverage`, `make coverage-branches` |
| Infection with 100 % MSI | The tests detect behaviour changes | CI, `make infection` |
| `composer audit`, `roave/security-advisories`, Dependabot | Known vulnerable dependencies | CI, Dependabot |
| CodeQL on the GitHub Actions workflows | Workflow injection, excessive permissions | `.github/workflows/codeql.yml` |
| OpenSSF Scorecard | Supply-chain practices | `.github/workflows/scorecard.yml` |
| SLSA provenance attestation and `SHA256SUMS` | Provenance of release archives | `.github/workflows/release.yml` |
| GitHub secret scanning with push protection | Committed secrets | Repository setting |

## 7. Assumptions on the host application

The assurance above holds if the host application:

1. keeps the subscriber password in a secret store or environment variable, encrypted at rest (a literal in committed
   configuration ends up in the compiled container), and restricts who can read it;
2. provides a PSR-18 client that verifies TLS certificates, with safe redirect behaviour, and does not override the
   hosts with a non-HTTPS URL outside tests;
3. protects its database and file storage (access control, encryption at rest, optionally column encryption of the NIR
   through a custom DBAL type), bounds the retention of backups, and applies a retention and erasure policy to the
   personal data and the returns it stores;
4. does not log request or response bodies at its own HTTP-client level, and does not put personal data in the
   messages of exceptions raised by its own listeners.

## 8. Residual risks

- The password is transmitted in clear inside the TLS channel because the URSSAF protocol requires it; it cannot be
  removed by the package.
- The token lifetime, the maximum polling range and several XML details are not published by URSSAF (see
  [api-reference §5](../api-reference.md)); the defensive defaults above apply until URSSAF documents them.
- Every item marked *To implement* is a commitment, not a verified control, until the code lands.

## 9. Keeping the case valid

The case is revisited when a change touches a trust boundary: a new outbound call, a new persisted field holding
personal data, a new logging statement, a new dependency, a new workflow permission. Pull requests run the full
quality gate (`make ci`).
