# ADR 0002 — Hosts per service

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0001](0001-xml-token-authentication.md),
  [ADR 0003](0003-declaration-mode-and-simulator-guard.md), [ADR 0009](0009-coexistence.md)

## Context

The reference has one host per environment (`Environment::Sandbox|Production`) and the skeleton copied it
(`Environment` with `https://api-edi.urssaf.fr` / `https://api.urssaf.fr`, `UrssafEndpoints` prefixed by
`/authentifier_dpae`). The DPAE specification does not fit:

- The Swagger declares `host: api.urssaf.fr`, `basePath: /authentifier_dpae`, but its own 404 examples show paths
  without the base path ([api-reference.md](../api-reference.md) §1 "Hosts per Swagger", §5.1).
- NAT/5492 ("Table d'adressage") publishes four hosts: authentication `https://mon.urssaf.fr/authentifier_dpae`,
  deposit `https://depot.dpae-edi.urssaf.fr`, consultation `https://consultation.dpae-edi.urssaf.fr`, download
  `https://telechargement.dpae-edi.urssaf.fr`; **concentrators add the port `8443`** to the last three, not to the
  authentication URL (§1 "Hosts per PDF").
- There is no sandbox host: the same hosts receive test and real flows (§1 "Test vs real", §3.3).
- The PDF writes the deposit path `/deposer-dsn/1.0/` (trailing slash), the Swagger `/deposer-dsn/1.0` (§2.2).
- A `retour` may carry a `url` "permettant le téléchargement" (guide V1.1); `id` is kept "pour rétrocompatibilité
  uniquement" and is optional (§2.3, §2.5). The download needs the `Authorization: DPAELogin jeton=…` header (§2.5).
- §4.16 (spec fidelity): the PDFs prevail for addresses, the Swagger for paths and field constraints.
- The URSSAF packages are tested together in one application against an HTTP mock server, which must replace every
  URSSAF host, the authentication endpoint included ([ADR 0009](0009-coexistence.md)).

## Decision

### Four base URIs

- `Environment` is **removed**, with its `Sandbox` case. There is no environment option in the package: what the
  reference calls "production" is the real declaration mode ([ADR 0003](0003-declaration-mode-and-simulator-guard.md)).
- `Infrastructure\Urssaf\ServiceEndpoints` holds one base URI per `Infrastructure\Urssaf\DpaeService` (backed enum
  `authentication`, `deposit`, `consultation`, `download`). Defaults from NAT/5492:

  | Service | Default base URI | Path (Swagger, §2) |
  |---|---|---|
  | authentication | `https://mon.urssaf.fr` | `/authentifier_dpae` |
  | deposit | `https://depot.dpae-edi.urssaf.fr` | `/deposer-dsn/1.0` |
  | consultation | `https://consultation.dpae-edi.urssaf.fr` | `/lister-retours-declarant/1.0/{dateDebut}[/{dateFin}]` |
  | download | `https://telechargement.dpae-edi.urssaf.fr` | `/telecharger-retour/1.0/{idFlux}/{idRetour}` |

- `UrssafEndpoints` keeps the four path constants **without** the `/authentifier_dpae` prefix (only the authentication
  path is `/authentifier_dpae`). The deposit path has no trailing slash (Swagger prevails for paths, §4.16); the PDF
  variant `/deposer-dsn/1.0/` is an open point (§5.1) settled by the contract tests.
- Every base URI is overridable, the authentication one included, through the configuration (`hosts.authentication`,
  `hosts.deposit`, `hosts.consultation`, `hosts.download`, each accepting `%env()%`) and the standalone builder. A base
  URI is `scheme://host[:port][/path]` with no query nor fragment, validated at build time when it is a literal and at
  the first call otherwise. A path prefix is kept and the service path appended to it, so the Swagger reading
  (`https://api.urssaf.fr/authentifier_dpae` for all four) stays expressible by configuration only. The discarded
  option of a dedicated "single host plus base path" switch (the proposal of the former §4.2, ADR 0000) is not implemented: it would
  duplicate what four overrides already express.

### Concentrator port

- The port `concentrator_port` (default `8443`, §1 "Hosts per PDF") is added to the **default** deposit, consultation
  and download URIs when the tenant's `ServiceCode` is `98` (`ServiceKind::Concentrator`, the code that gets "un jeton
  en tant que concentrateur", §2.1). It is never added to the authentication URI.
- An **overridden** base URI is used verbatim for every tenant, port included: the package never adds the port to it.
  This is what lets the MockServer (or a gateway) stand for all hosts.
- An unknown `ServiceCode` (e.g. `97`) gets no port; the tenant can still override the hosts.

### Policy for the `url` of a return

This reverses the former §4.9 wording ("prefer the `url` of a `retour` when set") and that of ADR 0000 ("return `url` honoured for downloads"), because the download sends
the token: following any API-supplied URL would hand a credential to whatever host the answer names.

- The `url` is honoured **only if its origin (scheme, host and effective port) equals the origin of the configured
  download base URI for the tenant** (concentrator port included when it applies; `443` is the effective port of an
  `https` URI without a port). Host names are compared case-insensitively. The URL is then used as is (path and query).
- Otherwise the `url` is ignored and the request is rebuilt as `/telecharger-retour/1.0/{idFlux}/{idRetour}` on the
  download base URI, from `flux.id` and `retour.id` (local checks `idFlux` 1–50, `idRetour` 1–10, §2.5).
- When the `url` is not honoured and `retour.id` is absent, the return is not downloadable: `DownloadReturn` throws
  `Application\Port\Urssaf\ReturnNotDownloadable` (not retryable, the `url` origin is reported but never followed). A
  return without `url` nor `id` is listed but not downloadable either (a `KO` may come without document, §2.3).
- HTTP redirects are never followed (the Symfony bridge sets `max_redirects: 0` on its client; a 3xx is an
  `UnexpectedUrssafResponse`, [ADR 0004](0004-xml-gzip-client.md)), for the same reason.

## Consequences

- The skeleton classes `Environment` and its test are deleted, and `UrssafEndpoints` changes, before any business code
  (ADR 0000 "Consequences").
- Pointing the package at the MockServer, or at `api.urssaf.fr` if the contract test shows the Swagger is right, is a
  configuration change only.
- A `url` on another host (for instance a CDN or a host that changed) is never followed; such a return is downloaded
  through `id` while it exists. If the contract tests show that real `url` values use another origin, the allowed
  origins become a configuration list (open point §5.1).
- The `concentrator_port` default stays the published `8443` and is configurable in case it changes.

## Amendment 2026-11-22 — URI checks and class names (lot 4)

- The classes are `Infrastructure\Urssaf\DpaeService`, `ServiceEndpoints` (`defaults()`, `withBaseUri()`,
  `withConcentratorPort()`, `baseUri()`, `uri()`, `isOnDownloadOrigin()`) and the internal `WebUri` (parsing and origin).
- A configured base URI **and** a return `url` must be an absolute `http` or `https` URI with a host and no credentials
  (`user@`, whatever the host), no whitespace, control character or backslash, and port `0` is refused. `http` is
  accepted for a base URI so a mock server can stand for a host; a `url` is trusted only if its scheme equals the
  configured one, so an `https` to `http` downgrade is refused. The origin is compared as `scheme://host:port`, scheme
  and host lower-cased, `443` / `80` made explicit.
- The port is added to a default URI by string concatenation; a configured `concentrator_port` outside 1..65535 is
  refused (`concentrator_port.out_of_range`).
- A refused `url` without `retour.id` throws `ReturnNotDownloadable::urlOutsideDownloadOrigin()` carrying only the
  origin of the `url` (`unparsable` when it has none).
