# ADR 0004 — XML / gzip / ISO-8859-1 client

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0001](0001-xml-token-authentication.md),
  [ADR 0005](0005-deposit-result-and-unknown-outcome.md), [ADR 0006](0006-polling-constraints.md)

## Context

The reference talks JSON through `JsonApiClient` and reads answers with a `Payload` reader and a structured error
catalogue. The DPAE API exchanges XML, gzip and PDF ([api-reference.md](../api-reference.md) §1 "Content types",
"Headers", §1.3):

- authentication: request `application/xml`, answer `application/octet-stream` (§2.1);
- deposit: the body is the DPAE flow gzip-compressed (PDF example `Content-Type: text/plain`, `Content-Encoding:
  gzip`; the Swagger declares no request type), answer `application/xml` (§2.2, §5.5);
- lists: `application/xml`; download: `application/xml` or `application/pdf`, given by the response `Content-Type`
  (§2.3–§2.5);
- answers are served with gzip support: the client must accept gzip or send no `Accept-Encoding`, 406 otherwise (§1);
- the example flow declares `encoding="ISO-8859-1"` (§2.2);
- error bodies are Apache HTML pages with a `<!DOCTYPE HTML PUBLIC …>`; there is no structured error payload; business
  errors come in the returns (§1.3);
- the XML schemas of the answers are not provided (§5.9).

## Decision

### Transport

- `Infrastructure\Urssaf\Http\XmlApiClient` replaces `JsonApiClient`. It is built on PSR-18 / PSR-17 only, sends the
  `Authorization: DPAELogin jeton=…` header ([ADR 0001](0001-xml-token-authentication.md)) and returns an internal
  `HttpAnswer` (status, lower-cased headers, body bytes). The Application layer never sees PSR-7.
- Deposit request: body `gzencode()` of the flow bytes (`ext-zlib`, default level), headers
  `Content-Encoding: gzip` and `Content-Type: text/plain` as in the PDF example (§4.5). The content type is a
  configuration option (`deposit_content_type`) because the Swagger declares none (§5.5). The flow bytes are sent
  unchanged (never re-encoded); a flow is gzipped once, never twice, and archives are never built (§1.2).
- Authentication request: `Content-Type: application/xml` (the Swagger enum), body from `IdentifiantsXmlWriter`.
- Every request sends `Accept-Encoding: gzip`. When an answer still carries `Content-Encoding: gzip` (the PSR-18
  client did not decode it), the client decodes it itself, stopping at `max_response_bytes` decoded bytes (default
  `100000000`, a package default against decompression bombs, not a specification value; `null` disables it): a
  larger answer is an `UnexpectedUrssafResponse`. Any other content coding is an `UnexpectedUrssafResponse`.
- Optional local size limit of the deposited flow, `max_flow_bytes` (default `100000000`, the "100 Mo" of the FAQ,
  §1.2), checked on the **uncompressed** flow because whether the limit applies before or after compression is not
  published (§5.16). `null` disables it. No other local limit.
- Redirects are never followed ([ADR 0002](0002-hosts-per-service.md)).

### Reading XML

- `Infrastructure\Urssaf\Wire\SafeXmlReader` is the only entry point to `ext-dom` for answers and for raw flows:
  `DOMDocument::loadXML()` with `LIBXML_NONET` only (no `LIBXML_NOENT`, no `LIBXML_DTDLOAD`, no `LIBXML_DTDATTR`, no
  `LIBXML_PARSEHUGE`), internal error collection, and **refusal of any document carrying a DOCTYPE** (an answer →
  `UnexpectedUrssafResponse`; a caller's flow → `ValidationFailed`, code `flow.dtd_not_allowed`). The official
  example flow has no DOCTYPE.
- Encoding: the encoding declared by the XML declaration wins. Without a declaration, the `charset` parameter of the
  `Content-Type` answer header is honoured (the bytes are converted to UTF-8 with `ext-mbstring` before parsing);
  without either, XML's default (UTF-8) applies. Values handed to the Domain are always UTF-8 strings.
- Writing: the builder of [ADR 0007](0007-declaration-model.md) writes flows in ISO-8859-1 with the XML declaration
  of the example; a character outside ISO-8859-1 is a validation error, never a silent substitution.
- Wire readers (`EnvoiXmlReader`, `RetoursXmlReader`) read element names verbatim from the Swagger definitions and
  tolerate unknown elements and attributes. Where the XML form (child element versus attribute) is not published
  (§2.3, §5.9), the readers accept both and keep the raw XML of the answer next to what they extract.
- Downloads are never parsed by the client: `ReturnContent` keeps the raw bytes and the response `Content-Type`
  ([ADR 0006](0006-polling-constraints.md), [ADR 0008](0008-doctrine-persistence.md)).

### Error answers

- The body of every non-2xx answer is discarded unread, with one exception: the deposit `422`, which is the rejection
  notice ([ADR 0005](0005-deposit-result-and-unknown-outcome.md)). Only the status, `WWW-Authenticate` and
  `Retry-After` are used. Bodies are never logged (they may echo personal data).
- A 2xx answer whose `Content-Type` is `text/html` is an `UnexpectedUrssafResponse` (lists, download) or an unknown
  outcome (deposit, [ADR 0005](0005-deposit-result-and-unknown-outcome.md)).
- Mapping shared by every operation (operation-specific cases in ADR 0001, 0005, 0006):

  | Answer | Exception |
  |---|---|
  | 401 | by realm, [ADR 0001](0001-xml-token-authentication.md) |
  | 400 (deposit, §2.2) | `UrssafRequestRejected` (`deposit.bad_request`) |
  | 403 (download, §2.5) | `UrssafRequestRejected` (`return.not_owned`) |
  | 404 (download, §2.5) | `Application\Port\Urssaf\ReturnNoLongerAvailable` (unknown or purged return) |
  | 404 (other operations) | `UnexpectedUrssafResponse` (wrong host or path) |
  | 406, 415 | `UnexpectedUrssafResponse` (package or configuration error: nothing processed) |
  | 422 | deposit: rejection notice (ADR 0005); lists: polling constraint (ADR 0006); download: `UrssafRequestRejected` (`return.invalid_parameters`); authentication: ADR 0001 |
  | 429 | `PollingConstraintViolated`, retryable (ADR 0006) |
  | 1xx, 3xx, other 4xx | `UnexpectedUrssafResponse` |
  | 5xx, transport error | `UrssafUnavailable` (retryable for reads; unknown outcome for the deposit, ADR 0005) |

## Consequences

- `ext-dom`, `ext-libxml`, `ext-zlib` and `ext-mbstring` join the runtime requirements with the first code that uses
  them; `ext-json` is not required.
- XXE, network entity loading and billion-laughs documents are refused by construction, both in answers and in caller
  flows.
- Without published answer schemas the readers are tolerant and keep the raw XML; they are tightened against the
  samples captured by the contract tests (§5.9).

## Amendment 2026-11-22 — ext-dom in the Domain for `DpaeFlow`

"`SafeXmlReader` is the only entry point to `ext-dom`" is relaxed for the raw flow only: the Domain class
`DpaeFlow` (ADR 0003 amendment) parses the caller's bytes itself, to check well-formedness, the absence of a DOCTYPE,
the root element and the test indicator, so that a `DpaeFlow` cannot exist unchecked. It uses `DOMDocument::loadXML()`
with the **same libxml options** as `SafeXmlReader`, exposed as `DpaeFlow::LIBXML_OPTIONS` (`LIBXML_NONET`; no
`LIBXML_NOENT`, `LIBXML_DTDLOAD`, `LIBXML_DTDATTR` nor `LIBXML_PARSEHUGE`; no DTD, no external entity), with internal
error collection and no libxml state leaked. Answers from URSSAF still go through `SafeXmlReader` only.

Equivalence is tested, not assumed: `DpaeFlowLibxmlOptionsTest` runs a hostile corpus (external file entity, network
entity, internal entity, external subset) through the options and through `DpaeFlow::fromXml()`. `SafeXmlReader`'s own
test must run the same corpus with `DpaeFlow::LIBXML_OPTIONS` when the class is written (lot 3): it does not exist yet.
