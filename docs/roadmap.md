# Implementation roadmap

Specification: [api-reference.md](api-reference.md). Architecture, conventions and quality gates are the same as in
`thebeardedbear/urssaf-tiers-prestations` (hexagonal layers enforced by Deptrac, PSR-only runtime, Symfony and
Doctrine bridges, 100 % coverage and MSI), with one major difference: **this API is not OAuth2 and not JSON**. It
uses an XML authentication call returning an opaque token, and XML (plus PDF) payloads.

## Already in place (skeleton)

- `SpecVersion::CURRENT` (`1.0.0`).
- `Infrastructure\Urssaf\Environment` (Sandbox / Production base URIs) and `Infrastructure\Urssaf\UrssafEndpoints`
  (Swagger paths under the base path `/authentifier_dpae`). **Both must change** (api-reference §4.2, §5.1):
  - the PDF publishes four dedicated hosts (`mon.urssaf.fr/authentifier_dpae`, `depot.dpae-edi.urssaf.fr`,
    `consultation.dpae-edi.urssaf.fr`, `telechargement.dpae-edi.urssaf.fr`, port `8443` for concentrators) instead of
    `api.urssaf.fr` + base path;
  - there is no sandbox host: test vs real is the test indicator in the deposited flow, so `Sandbox` goes away (or
    becomes a configuration of the declaration mode, not of the host).

## Domain (`src/Domain`)

- `Declarant\DeclarantCredentials` (`siret`, `nom`, `prenom`, `motdepasse`, `service`), `Declarant\Siret` (14 chars),
  `Declarant\ServiceCode` (`25` direct, `98` concentrator; unknown integers preserved, §4.11).
- `Authentication\DpaeToken` (opaque base-64 string; never logged, never persisted).
- `Declaration\DeclarationMode` (`Test|Real`, §3.3), `Declaration\DpaeFlow` (raw XML flow + mode; well-formedness
  check only at first). Domain model of the declaration (employer, employee, contract) and XML builder: later phase.
- `Deposit\FlowId` (`IdFluxDPAE`, 1–50), `Deposit\ElectronicReceipt` (AEE), `Deposit\RejectionNotice` (ARE),
  `Deposit\DepositResult` (sum type: `ElectronicReceipt|RejectionNotice`).
- `Return\ReturnReference` (flux id, return id 1–10, nature, statut, publication, production, ops, delegataire, url),
  `Return\ReturnNature` (enum `10|11|20|41`, unknown preserved, §3.1), `Return\ReturnStatus` (`OK|KO|ANO|EN_COURS`),
  `Return\ReturnContent` (raw bytes + content type, XML or PDF).
- `Domain\Exception\*`.

## Application (`src/Application`)

- Ports `Port\Urssaf\DpaeGateway` (`authenticate`, `deposit(DpaeFlow, DeclarationMode)`, `listReturns(from, ?to)`,
  `downloadReturn(flowId, returnId)`), `CredentialsProvider`, `TokenStore` (in-memory default; the token is a
  credential, no persistent default).
- Commands/queries: `DepositDpae` / handler, `ListReturns` / handler (one-date and two-date paths),
  `DownloadReturn` / handler (uses `url` when set), `SynchronizeReturns` (poll since the last known publication date,
  store new returns; needs the Doctrine bridge or an in-memory fake).

## Infrastructure (`src/Infrastructure`)

- `Urssaf\Http\DpaeTokenProvider`: `POST` of the XML `identifiants`, base-64 token from the response body, re-auth on
  401 with realm `Expiration du jeton`. **`OAuth2TokenProvider` from Tiers de prestations is not reusable.**
- `Urssaf\Http\XmlApiClient` (XML/binary instead of the JSON client; gzip request body via `ext-zlib`; gzip-aware
  `Accept-Encoding`; `Authorization: DPAELogin jeton=…`), mapping 401 by `WWW-Authenticate` realm (§1.3), 403/404/406/
  415/422/429.
- `Urssaf\UrssafHttpGateway` + `Urssaf\Wire\*` (`IdentifiantsXmlWriter`, `RetoursXmlReader`, `EnvoiXmlReader`,
  `yyyyMMddHHmmss` date formatter). Readers use `ext-dom` with `LIBXML_NONET`, no DTD, honour ISO-8859-1.
- `Urssaf\ApiConfiguration`: four overridable service base URIs (defaults from the PDF), optional port `8443`,
  credentials, default `DeclarationMode`, optional max payload size.
- `UrssafEndpoints` reduced to path constants without the `/authentifier_dpae` prefix for the deposit and return
  services (the authentication path stays `/authentifier_dpae`).

## Bridges

- Symfony bundle `UrssafDpaeBundle` (alias `urssaf_dpae`): configuration (credentials, `service`, host overrides,
  `concentrator` port flag, declaration mode), HTTP client, console commands `urssaf:dpae:deposit <file>`,
  `urssaf:dpae:returns [--from] [--to]`, `urssaf:dpae:download <flowId> <returnId>`.
- Doctrine bridge: **probably needed**. Returns are purged by URSSAF after 3 months and the consultation history is
  13 months, so deposits (flux id, declarant, mode, outcome) and returns (reference, content) are persisted to
  reconcile and keep them; includes the migration and a repository for `SynchronizeReturns`.

## Runtime dependencies to add with the code

`ext-dom`, `ext-libxml`, `ext-zlib`, `ext-mbstring`, `psr/http-client`, `psr/http-factory`, `psr/http-message`,
`psr/clock`, `psr/log`, plus the matching `suggest` entries (Symfony and Doctrine bridges). `ext-json` is not needed by
the API itself.

## Tests

- Unit: value objects, XML writers/readers against `tests/Fixtures/wire/*` (currently only
  `deposit_request_minimal.xml`; the other wire examples are not published and must be captured, see
  api-reference §5), handlers with a fake gateway, token provider (re-auth on expired token), 401 realm mapping.
- Functional: Symfony bundle wiring, Doctrine mapping.
- Contract (`make test-contract`, manual CI job): real credentials (an *Urssaf en ligne* subscriber, or a registered
  concentrator) in `URSSAF_DPAE_SIRET/NOM/PRENOM/PASSWORD/SERVICE`, optional host overrides. Always deposits with the
  **test indicator** (`TST`/`120` to be confirmed) and never in real mode. First goals: settle the host and path
  discrepancy, the test indicator semantics, the authentication XML shape, and capture official samples of the AEE,
  rejection notice, list response and returns.

## Order of work

1. Settle the open points that block the design with a first contract test (hosts, authentication XML, test
   indicator); update `Environment`/`UrssafEndpoints`.
2. Domain value objects and result sum types.
3. Wire writers/readers + fixtures.
4. HTTP gateway (authentication, deposit, lists, download, error mapping, 429/422 handling).
5. Application commands/queries.
6. Symfony bridge.
7. Doctrine bridge and `SynchronizeReturns`.
8. Contract tests, then remove the open points from `api-reference.md`; later phase: declaration domain model and
   XML builder.
