# ADR 0001 — XML token authentication

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0002](0002-hosts-per-service.md),
  [ADR 0004](0004-xml-gzip-client.md)

## Context

The reference gets an OAuth2 access token (`client_credentials`) with a published lifetime and keeps it in a
`TokenStore` with a safety margin. The DPAE API has no OAuth2 ([api-reference.md](../api-reference.md) §1 "Security",
Swagger `securityDefinitions` empty):

- `POST /authentifier_dpae` with an XML `identifiants` body of five required fields (`siret`, `nom`, `prenom`,
  `motdepasse`, `service`) returns "Jeton encodé en base 64" as `application/octet-stream` (§2.1).
- The token is sent unchanged as `Authorization: DPAELogin jeton=<token>` on every other call (§1 "Headers").
- Its lifetime, size and charset are not published (§2.1, §5.6). The XML root, namespace, element order and encoding
  of `identifiants` are not published either: no XML example exists, the root name is inferred from the Swagger
  definition name (§2.1, §5.4).
- 401 answers are told apart only by the `WWW-Authenticate` realm, both on the authentication call and on the other
  services (§1.3). The authentication call also answers 404 and 422 "Paramètres invalides (dont contraintes de format
  Net-E)" (§2.1).
- The password "est transmis en clair à chaque authentification" and must be encrypted if stored by the software
  (§2.1). `service` is documented as `98` (concentrator) or `25` (direct); the example is `97` (§2.1, §5.4).
- For a concentrator the concentrator SIRET gets the token, the declarant SIRET is used in the flows (§2.1, FAQ).

## Decision

### Credentials and token

- `Domain\Declarant\DeclarantCredentials` (`siret`, `nom`, `prenom`, `motdepasse`, `service`): `final readonly`, not
  serializable, `#[SensitiveParameter]` on the password, `__debugInfo` masking the password. Local checks are only the
  published Swagger constraints (§2.1): `siret` exactly 14 characters, `nom` 1–64, `prenom` 1–39, `motdepasse` 8–30.
  The name pattern is **not** checked locally: the published pattern lost its accented letters (§5.4), so applying it
  would refuse valid names. URSSAF checks it (422).
- `Domain\Declarant\ServiceCode`: raw integer kept next to a nullable backed enum `ServiceKind::fromCode(): ?self`
  (`Direct` = `25`, `Concentrator` = `98`). Any other integer (the example `97`) is accepted and sent as is.
- `Domain\Authentication\DpaeToken`: opaque string, never logged, never serialized, masked by `__debugInfo`. The body of
  the 200 answer is used verbatim after removing leading and trailing ASCII whitespace (a base-64 value contains none);
  an empty body is an `UnexpectedUrssafResponse`. The package never decodes the base 64.
- One tenant is one authenticating identity (direct declarant or concentrator), read through
  `Application\Port\CredentialsProvider::forTenant(TenantId)`. The static adapter is `InMemoryCredentialsProvider`
  ([ADR 0009](0009-coexistence.md)).

### Token provider

- `Infrastructure\Urssaf\Http\DpaeTokenProvider` replaces `OAuth2TokenProvider` (not reusable). It writes the body with
  `Infrastructure\Urssaf\Wire\IdentifiantsXmlWriter`: root `identifiants`, no namespace, children in the order of the
  Swagger properties (`siret`, `nom`, `prenom`, `motdepasse`, `service`), text content escaped by `ext-dom`. The XML
  encoding of this body is a configuration option (`authentication_encoding`, default `UTF-8`) because none is
  published (§5.4). The writer sits behind the `Infrastructure\Urssaf\Wire\CredentialsXmlWriter` interface so that a
  different shape found by the contract tests is a one-class change, not a configuration matrix.
- The token is cached **in memory only**, per tenant and per process (`InMemoryTokenStore`, the only adapter shipped).
  It is a credential: the package ships no persistent or shared token store, and no cache pool is used for it. Each
  worker authenticates on its first call.
- No proactive expiry: the lifetime is not published (§5.6). Re-authentication is reactive:
  - a 401 with realm `Expiration du jeton` on a deposit, list or download drops the cached token, authenticates again
    and replays the call **once**. A 401 proves the call was not processed, so the replay is safe even for the deposit
    ([ADR 0005](0005-deposit-result-and-unknown-outcome.md)). A second 401 is thrown as is;
  - a 401 with realm `Jeton manquant ou invalide` drops the cached token and is thrown (no replay: the specification
    gives this realm as an authentication error, not as an expiry, §1.3). The next call authenticates again.

### 401 mapping

The realm is read from `WWW-Authenticate` (scheme `Basic` or `DPAELogin`, value between `"…"` or `«…»`, as printed in
§1.3) and mapped to `Domain\Exception\AuthenticationFailure` (backed enum, raw realm kept):

| Call | Realm (§1.3) | `AuthenticationFailure` | Exception |
|---|---|---|---|
| Authentication | `Expiration du mot de passe` | `PasswordExpired` | `UrssafAuthenticationFailed` (credential refusal) |
| Authentication | `net-entreprises.fr` | `InvalidCredentials` | `UrssafAuthenticationFailed` (credential refusal) |
| Any | `Utilisateur non-inscrit au service` | `NotRegistered` | `UrssafAuthenticationFailed` (credential refusal) |
| Other services | `Jeton manquant ou invalide` | `TokenInvalid` | `UrssafAuthenticationFailed` (token dropped) |
| Other services | `Expiration du jeton` | `TokenExpired` | replay once, then `UrssafAuthenticationFailed` |
| Any | missing or other realm | `Unknown` | `UrssafAuthenticationFailed` |

A credential refusal (`PasswordExpired`, `InvalidCredentials`, `NotRegistered`) parks the records of the tenant in
`invalid_credentials` until they are requeued, as in the reference (ADR 0000 §9). The realm text is not personal data
and may be logged; the HTML body is never read ([ADR 0004](0004-xml-gzip-client.md)).

Other answers of the authentication call: 422 → `UrssafRequestRejected` (`authentication.invalid_parameters`, not
retryable: the identity does not meet the Net-Entreprises format constraints); 404, 406, 415 and any other 4xx →
`UnexpectedUrssafResponse` (configuration error); 5xx and transport errors → `UrssafUnavailable`, retryable (the
authentication call writes nothing).

## Consequences

- No token ever reaches a database, a cache, a log or a Messenger message; the cost is one authentication per process
  and per tenant, plus one after each expiry.
- The authentication body shape, its encoding and the `97` example stay open points (§5.4) settled by the contract
  tests; a different shape changes only `IdentifiantsXmlWriter`.
- Hosts that store the password must encrypt it themselves (`CredentialsProvider`), as stated by §2.1; the package
  never stores it.
- This ADR refines ADR 0000 §"Authentication": the token store is in memory only, with no opt-in shared store.
