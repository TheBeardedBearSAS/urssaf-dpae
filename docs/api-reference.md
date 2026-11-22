# URSSAF "API DPAE" — technical reference

This document is the single source of truth used by the package for enums, validation rules and wire fixtures.
It summarises the official specification of the *Déclaration Préalable À l'Embauche* (DPAE) API:

- Swagger contract: **v1.0.0** (`docs/openapi/dpae-prd.json`, Swagger 2.0, production only)
- Documentation PDFs (none is versioned in its file name; all dated October 2021, `docs/reference/`):
  - **"L'API DPAE — Régime général — Principes de fonctionnement"**, NAT/5491, Oct. 2021 (`5491-API-DPAE-Fonctionnement-2021.pdf`)
  - **"L'API DPAE — Guide d'implémentation de l'API"**, NAT/5492, Oct. 2021; revision history V1.0 (2015-07-17), V1.1 (2017-03-30, `<url>` tag added to the return lists) (`5492-DPAE-Guide-ImplementationAPI-Oct2021.pdf`)
  - **"L'API DPAE — Questions/Réponses"**, NAT/5493, Oct. 2021 (`5493-API-DPAE-FAQ-Oct2021.pdf`)
- Catalogue page: https://portailapi.urssaf.fr/fr/catalogue-api/prd/dpae
- PDFs (same base for the three): https://cedito-medias.urssaf.fr/medias/PortailAPI/files/documentationAPIs/DPAE/5491-API-DPAE-Fonctionnement-2021.pdf,
  `…/DPAE/5492-DPAE-Guide-ImplementationAPI-Oct2021.pdf`, `…/DPAE/5493-API-DPAE-FAQ-Oct2021.pdf`
- Swagger `externalDocs`: https://www.dpae-edi.urssaf.fr/5492-API-DPAE-Guide-Implementation.pdf

The package constant `SpecVersion::CURRENT` must equal `1.0.0`. Rule shared by the URSSAF packages: `SpecVersion` is
the version in the **file name of the published PDF** (the most detailed source), falling back to the Swagger version
when the PDF is not versioned. The three PDFs carry only a date, so the Swagger version `1.0.0` applies. Field names,
element names, codes and wire values below are reproduced verbatim from the specification (they are French by design);
everything else in the package is English.

The API is a **port of the DSN "API" mode** (same interfaces, other addresses and exchanged elements). The PDFs defer
to the DSN guide (*DSN_Guide-implementation-API* V3.5, dsn-info.fr, not provided) and to documents not provided here
(*Dessin d'enregistrement DPAE*, `dsn_bilans_v01r06.xsd`); everything those documents would specify is flagged
"not published" below.

> Status: specification, being implemented. Lots 1 to 4 of the [roadmap](roadmap.md) exist (domain value objects, the
> declaration model, the XML wire codecs and the PSR-18 HTTP gateway); the use cases and the bridges do not yet.

> **Source and rights.** The specification, the field names, the codes and the official JSON examples quoted here come
> from the URSSAF documents listed above and remain URSSAF's property. They are reproduced only so the package can
> interoperate with the API, and they are not covered by this package's MIT license. In case of discrepancy, the
> official URSSAF documentation prevails. The official documents themselves are not versioned: download them into
> `docs/reference/`, a local git-ignored folder. This package is not affiliated with URSSAF.

---

## 1. General

| Topic | Value |
|---|---|
| Purpose | Lets DPAE-producing software (payroll software, concentrators, SaaS) authenticate directly on the URSSAF DPAE deposit point and send a very large number of declarations, getting back electronic receipts (AEE), conformity certificates and the business report (*accusé de réception* of the DPAE). |
| Access | Catalogue: production only, restricted access, subscription form. FAQ (NAT/5493): a company or third-party declarant needs **no specific registration** but at least one subscriber registered on the *Urssaf en ligne* service; a **concentrator must register** by e-mail to `api.dpae@acoss.fr` (subject `Inscription Concentrateur`; body: concentrator SIRET, subscriber name, first name and e-mail), even if already registered to the DSN API. |
| Security | **Not OAuth2.** Token authentication: `POST` of an XML `identifiants` body returns a token encoded in base 64 (`application/octet-stream`); the token is then sent as `Authorization: DPAELogin jeton=…` on every other call. Credentials are those of an *Urssaf en ligne* subscriber (SIRET, name, first name, password), see §2.1. Swagger `securityDefinitions` is empty. |
| Hosts per Swagger | `host: api.urssaf.fr`, `basePath: /authentifier_dpae`, `schemes: [https]`: the five paths below are relative to `https://api.urssaf.fr/authentifier_dpae` (e.g. `https://api.urssaf.fr/authentifier_dpae/authentifier_dpae`). The 404 examples in the Swagger (`/authentifier_dpaes`, `/deposer-dsns/1.0`, `/lister-retours-declarants/1.0/20220130083000`) show paths **without** the base path. See §5. |
| Hosts per PDF (NAT/5492 "Table d'adressage") | Authentification `https://mon.urssaf.fr/authentifier_dpae`; Dépôt `https://depot.dpae-edi.urssaf.fr`; Retours – Consultation `https://consultation.dpae-edi.urssaf.fr`; Retours – Téléchargement `https://telechargement.dpae-edi.urssaf.fr`. **Concentrators must add the port `8443`** to the deposit, consultation and download URLs (not to the authentication URL). |
| Test vs real | No separate host and no sandbox in the catalogue. The mode is selected **inside the deposited flow**: `FR_DUE_Upload.Test.Indicator` (XML) / field `C_VERSION` (flat format), see §2.2 and §3.3. |
| Content types | Authentication: request `application/xml`, response `application/octet-stream`. Deposit: request body is the DPAE flow gzip-compressed (PDF example: `Content-Type: text/plain`, `Content-Encoding: gzip`; Swagger declares no request type), response `application/xml`. Lists: response `application/xml`. Download: `application/xml` or `application/pdf` (see the response `Content-Type`). Swagger global `consumes`/`produces: application/json` is not used by any operation. |
| Headers | `Authorization: DPAELogin jeton=<base64 token>` (required on deposit, lists, download; format from the Swagger parameter description). Optional `Content-Type: application/xml` on authentication (Swagger enum). Responses are served with `gzip` support: the client must accept gzip or send no `Accept-Encoding` (406 otherwise). |
| HTTP errors | Authentication 200, 401, 404, 422. Deposit 200, 400, 401, 404, 406, 415, 422. Lists 200, 401, 404, 406, 415, 422, 429. Download 200, 401, 403, 404, 406, 415, 422. Error bodies shown in the Swagger are Apache HTML pages (401, 404, 422), see §1.3. |

### 1.1 Quotas

No numeric quota is **published** (neither in the Swagger nor in the PDFs). Only polling constraints exist on the
list services: HTTP **429** "Le client ne respecte pas les contraintes de polling (étendue de la plage de recherche)";
the Swagger notes this is a temporary solution, **422 will be replaced by 429** "lorsque la version d'Apache le
permettra" (so both can be received today). The maximum extent of the search range is **not published**.

### 1.2 Known limitations (official)

- Deposit is **unit deposit only** (Swagger): one DPAE flow containing one rank ("un seul bloc S20", wording inherited
  from the DSN). If the constraint is not met "le service renverra quand même un AEE. C'est le bilan d'anomalie qui
  contiendra une erreur spécifique". See §5 for the contradiction with the PDFs.
- Maximum size of an API deposit: **100 Mo** (FAQ). Whether before or after compression is not published.
- Archives are **not accepted** on the API (Swagger, NAT/5492); NAT/5491 describes archives (`gz`, `tar`, `zip`,
  `gzip`) for the deposit point in general. The API body is a single gzip-compressed flow (`.gz`), see §5.
- DPAE XML and flat ("à plat") formats are both accepted (NAT/5492). Format `120`; flat records are **990** characters
  long (NAT/5491). The flat layout is not part of the documents provided.
- No "annule et remplace" function for a DPAE flow; no cinematic control (no declared period); duplicates are handled
  by the business control (code `98`, §3.5).
- Returns: follow-up data are available through the consultation service for **13 months** (same history as the
  dashboard); returns available for download are retained **3 months**, then purged and not archived (404 afterwards).
- The BIS (return 20) is available from D+1 of the hire date and only if an identification anomaly was found.
- Returns 20 and 41 exist in "real" mode only (§3.1).

### 1.3 Error payload

There is **no structured error payload**. Per the Swagger, error responses are Apache-style HTML documents
(`<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">` … `401 Unauthorized`, `404 Not Found`, `422 Unprocessable
Entity`). Business errors are carried by the **returns** (rejection notice, anomaly report, §3), not by HTTP errors,
except the deposit `422` which carries the rejection notice ("Avis de rejet"). Authentication failures are
distinguished by the `WWW-Authenticate` response header:

| Where | `WWW-Authenticate` value | Meaning |
|---|---|---|
| Authentication, 401 | `Basic realm=«Expiration du mot de passe»` | Password expired |
| Authentication, 401 | `Basic realm=«net-entreprises.fr»` | Unknown account or wrong password |
| Authentication, 401 | `DPAELogin realm=«Utilisateur non-inscrit au service»` | Declarant not registered to the DPAE |
| Other services, 401 | `DPAELogin realm=«Jeton manquant ou invalide»` | Authentication error (includes the *fiches de paramétrage*) |
| Other services, 401 | `DPAELogin realm=«Expiration du jeton»` | Token expired |
| Other services, 401 | `DPAELogin realm=«Utilisateur non-inscrit au service»` | Declarant not registered to the DPAE |

---

## 2. Operations

Swagger paths are given as published; the host to call is open point §5.1. Tags: `authentifier`, `deposer-dpae`,
`lister-retours-declarant`, `telecharger-retour`. Contact: "Support API Urssaf"
(https://acoss-dsi.atlassian.net/servicedesk/customer/portal/2).

### 2.1 Authenticate — `POST /authentifier_dpae`

`operationId`: `authentifierUsingPost-v1` (description "Requete d'authentification"). PDF host: `https://mon.urssaf.fr/authentifier_dpae`.

Header: `Content-Type` (optional, enum `application/xml`).

Body (`application/xml`, required): `identifiants`, all five fields required.

| Field | Type | Req. | Rules |
|---|---|---|---|
| `siret` | string | yes | Declarant SIRET, or concentrator SIRET (SIRET of the establishment that signed the concentrator charter). Exactly 14 characters (`minLength`/`maxLength` 14). Example `84080764800018` |
| `nom` | string | yes | Declarant name or concentrator company name. 1–64 chars, pattern `^([a-zA-ZeeeeaaauuuiioocEEEEAAAUUUIIOOC]+['\- ])*[a-zA-ZeeeeaaauuuiioocEEEEAAAUUUIIOOC]+$` (accented letters are flattened in the published Swagger; see §5). Example `John` |
| `prenom` | string | yes | First name of the declarant or concentrator subscriber. 1–39 chars, same pattern. Example `Doe` |
| `motdepasse` | string | yes | Password. 8–30 chars. "Le mot de passe est transmis en clair à chaque authentification. Par contre celui-ci doit être chiffré s'il est stocké dans un logiciel de paie." |
| `service` | integer (int32) | yes | Net-Entreprises service code of the DPAE. "98 pour obtenir un jeton en tant que concentrateur et 25 pour un jeton "direct" pour les cotisants". Example `97` (the example is not one of the two documented values, see §5) |

Authentication data (PDF): use the identifiers of the *Urssaf en ligne* subscriber plus the subscriber's name and first
name exactly as entered at registration (visible in *Mon profil* then *gérer mes coordonnées*). For a concentrator,
the **concentrator SIRET** is used here to get the token and the **declarant SIRET** is then used in the other flows
(FAQ).

The XML root element name, namespace and element order of `identifiants` are **not published** (no XML example in any
source): the element names are the Swagger property names above, the root `identifiants` is *inferred from the
definition name*.

Response 200: "Jeton encodé en base 64", `application/octet-stream` (`type: string, format: binary`). The token is
sent unchanged in `Authorization: DPAELogin jeton=<token>`. Lifetime, size and charset of the token are **not
published**.

Errors: 401 (headers in §1.3), 404 (Apache HTML page), 422 "Paramètres invalides (dont contraintes de format Net-E)".

Fixtures: none published (no official request or response example). See §5.

### 2.2 Deposit one DPAE flow — `POST /deposer-dsn/1.0`

`operationId`: `deposerDsnUsingPost-v1`. Description: "Service de dépôt d'une DPAE — Utiliser le server "depot"".
PDF: "Le nom de la ressource est bien /deposer-dsn, même dans le contexte dpae." PDF host
`https://depot.dpae-edi.urssaf.fr` (port `8443` for concentrators). The PDF example writes the path `/deposer-dsn/1.0/`.

Header: `Authorization: DPAELogin jeton=…` (required).

Body (required, no schema in the Swagger): content of the DPAE flow "tel qu'il est généré par le logiciel de paie" in
**gzip (`.gz`)**; archives are not accepted; XML and flat formats accepted. Minimal request of the PDF:

```
POST /deposer-dsn/1.0/ HTTP/1.1
Host: depot.dpae-edi.urssaf.fr
Authorization:
Content-Type: text/plain
Content-Encoding : gzip
Content-Length: 4096
```

followed by the XML flow (fixture `tests/Fixtures/wire/deposit_request_minimal.xml`). The PDF notes that the example
ignores the message transformations (gzip, chunk, ssl). The `Authorization` value is empty in the PDF example.

Pre-controls apply to the flow (NAT/5491): a non-empty file of at most the allowed size ("paramétrable", value not
published), a text or XML file whose extension is free, and the version field: "C_VERSION : la zone doit contenir la
(ou les) version(s) de la norme applicable(s) au moment du dépôt : 120 ou TST pour test, échec sinon".

Response 200: "Le retour de l'AEE ou de l'avis de rejet est synchrone": the body is directly the AEE or the rejection
notice (schema `envoi`, `application/xml`), downloadable again later through the return services. Official examples
are announced in the Swagger ("Exemple succès (AEE)", "Exemple avis de rejet") but the links are empty: **no example
published**.

`envoi` (Swagger schema; shared with §2.5):

| Field | Type | Rules |
|---|---|---|
| `envoi_identification` | object | `declarant` (`siret` 14 chars, `nom` 1–64, `prenom` 1–39, same definitions as in §2.1) |
| `envoi_bilan` | object | `envoi_etat`, typed as `retours` (§2.3) |

AEE content (minimum, NAT/5491): `IdFluxDPAE` (flow identifier set by the deposit system), flow name, name of the
archive if the flow belongs to one, reference of the identified declarant (the *triplet* name, first name, SIRET),
generation date of the receipt. Rejection notice content (minimum): same four first items plus the reject reason(s).
An AEE and a rejection notice are mutually exclusive for one flow. Formats of the returns 10 and 11 are the DSN ones
(FAQ; `dsn_bilans_v01r06.xsd`, not provided).

Errors: 400 "Requête incorrecte ou erreur fonctionnelle lors de la récupération"; 401 (headers in §1.3); 404;
406 (unsupported `Accept-Encoding`: must include gzip or be omitted); 415 "Type de média non supporté";
**422 "Avis de rejet"** (the rejection notice itself).

DPAE XML flow as shown by the single official example (inferred structure, element names verbatim; the full format
is the *Dessin d'enregistrement DPAE*, not provided). Values are the example's; no other rule is published here
(rules from the norm controls are in §3.4):

| Element | Example | Rules |
|---|---|---|
| `FR_DUE_Upload` (root, with namespaces `cct`, `rxdt`, `rxorg`, `rxpadr`, `rxpers`, `rxphadr`, `xsi`) | | XML declaration `<?xml version="1.0" encoding="ISO-8859-1" ?>` in the example |
| `FR_DUE_Upload.Test.Indicator` | `120` | Test indicator, see §3.3 |
| `FR_DuesGroup` > `FR_Employer` > `FR_EmployerIdentity` > `rxorg:FR_Organization.SIRET.Identifier` | `99999999999999` | Employer SIRET (14 digits *inferred from the example*) |
| `rxorg:FR_Organization.APE.Code` | `8110Z` | NAF/APE code (length 4 or 5, §3.4) |
| `FR_Employer.URSSAF.Code` | `257` | URSSAF code |
| `FR_EmployerAddress` | | `rxpadr:FR_PostalAddress.StreetDesignation.Text` (`44 rue DES TEST`), `rxpadr:FR_PostalAddress.Town.Text` (`CAEN`), `rxpadr:FR_PostalAddress.Postal.Code` (`14000`) |
| `FR_EmployerContact` > `FR_PhoneNumber` > `rxphadr:FR_PhoneAddress.PhoneNumber.Text` | `0000000000` | 10 digits |
| `FR_EmployeeGroup` > `FR_Employee` > `FR_EmployeeIdentity` | | `rxpers:FR_PersonIdentity.Surname.Text` (`TEST`), `rxpers:FR_PersonIdentity.ChristianName.Text` (`Alain`), `rxpers:FR_PersonIdentity.Sex.Code` (`1`) |
| `rxpers:FR_NNI` | | `rxpers:FR_NNI.NIR.Identifier` (`1000000000000`, 13 digits), `rxpers:FR_NNI.NIRKey.Text` (`99`) |
| `rxpers:FR_Birth` | | `rxpers:FR_Birth.Date` (`1970-01-01`, ISO date), `rxpers:FR_Birth.Town.Text` (`PARIS`) |
| `FR_EmployeeComplement` | | `FR_EmployeeComplement.Birth_Department.Code` (`75`) |
| `FR_Contract` | | `FR_Contract.StartContract.Date` (`2015-07-17`), `FR_Contract.StartContract.Time` (`12:30:00`), `FR_Contract.EndContract.Date` (`2015-09-15`), `FR_Contract.Nature.Code` (`CDI`), `FR_Contract.HealthService.Text` (`387`) |

Notes: the example's `FR_Contract.Nature.Code` is `CDI` while it carries an `EndContract.Date` (contract with an end
date), and the norm controls list the contract types as `1` (CDD), `2` (CDI), `3` (CTT) (§3.4): the XML value
vocabulary is **not published** (see §5). A file may contain several hires for one establishment (FAQ) and may be
multi-employer (NAT/5491 recommends ordering the DPAE of one employer successively), which conflicts with the
Swagger unit-deposit rule (§5).

Fixtures: `tests/Fixtures/wire/deposit_request_minimal.xml`.

### 2.3 List the returns from a date — `GET /lister-retours-declarant/1.0/{dateDebut}`

`operationId`: `lrdDeclarantPlageHoraireUsingGet-v1`. PDF host `https://consultation.dpae-edi.urssaf.fr`
(port `8443` for concentrators). The Swagger description is a copy of the DSN *fiches de paramétrage* search text
("1 à N `<fiche>` pour chacun des 0 à N `<declarant>`…") and does not describe the DPAE returns; it is not reproduced
(see §5). The PDF states that returns are searchable per declarant and per period, as in the DSN.

Header: `Authorization: DPAELogin jeton=…` (required).

| Field | Type | Req. | Rules |
|---|---|---|---|
| `dateDebut` (path) | string (`format: date-time`) | yes | Start of the search range. "Ce champ est comparé avec la date de publication d'un retour par un OPS (c'est-à-dire l'horodatage de son enregistrement sur le SI DPAE)". Pattern **`yyyyMMddHHmmss`** (e.g. `20220130083000`, from the Swagger 404 example). Time zone not published |

Response 200: `retours` (`application/xml`), "Les retours de plusieurs flux".

`retours`

| Field | Type | Rules |
|---|---|---|
| `flux` | array of `flux` | |

`flux`

| Field | Type | Req. | Rules |
|---|---|---|---|
| `id` | string | yes | Flow identifier, 1–50 chars. Example `F1spdeAilkR4q3UUlQA7haa` (the `IdFluxDPAE` of the AEE) |
| `retour` | array of `retour` | | |

`retour`

| Field | Type | Req. | Rules |
|---|---|---|---|
| `publication` | string (date-time) | yes | Date at which the SI DPAE made the return available to the search services. Format **`yyyyMMddhhmmss`** as written in the Swagger (lower-case `hh`; see §5) |
| `production` | string (date-time) | yes | Business date supplied by the OPS or the delegate that issued the return: production date of the document, or emission date for a mere notification. Same format |
| `ops` | string | no | Identifier of the OPS entered in the declaration (`S21.G00.70.002` and/or `S21.G00.15.002`, DSN rubrics) subject of the report. 5–10 chars. Not set for a return by the SI DPAE or a base organisation; always set when a delegate identifier is present; `OPSINCONNU` for an unreferenced body. Example `SI DPAE` |
| `delegataire` | string | no | Management delegate identifier entered in the declaration (`S21.G00.70.003` and/or `S21.G00.15.003`). 5–9 chars. Example `X2ACF` |
| `nature` | integer (int32) | yes | "Nature du retour permettant d'identifier de manière unique un retour, en provenance d'un même OPS destinataire. Il peut donc y avoir plusieurs retours de même nature mais en provenance d'OPS différents." Example `50`. For the DPAE the documented values are `10`, `11`, `20`, `41` (§3.1; the Swagger example `50` is not one of them) |
| `statut` | `Statut` | yes | `OK`, `KO`, `ANO`, `EN_COURS` (§3.2): "résultat du traitement métier à cette étape" |
| `id` | string | no | "Pour rétrocompatibilité uniquement, cf. « URL du téléchargement du retour »". 1–10 chars. Example `0123456789` (this is the `idRetour` of §2.5) |
| `url` | string | no | URL to download the return. Set only if a document is associated with this return nature and if the status allowed a document (a `KO` may come without one). Tag added in guide V1.1 |

The XML wire form (child element or attribute, element names) of `flux` and `retour` is **not published**: the
Swagger gives only the JSON-like property names above, and no example of a list response exists.

Errors: 401 (§1.3); 404; 406; 415; 422 "La requête n'a pas pu être traitée, car les paramètres sont invalides";
429 (polling constraints, §1.1).

Fixtures: none published.

### 2.4 List the returns of a range — `GET /lister-retours-declarant/1.0/{dateDebut}/{dateFin}`

`operationId`: `lrdDeclarantPlageHoraireFullUsingGet-v1`. Same service, host, header, response (`retours`) and errors
as §2.3, with an end date.

| Field | Type | Req. | Rules |
|---|---|---|---|
| `dateDebut` (path) | string (`format: date-time`) | yes | As §2.3, pattern `yyyyMMddHHmmss` |
| `dateFin` (path) | string (`format: date-time`) | yes (Swagger) | End of the search range, compared with the publication date, pattern `yyyyMMddHHmmss`. The description says "Le champ est facultatif afin de rechercher l'ensemble des retours à partir d'une date" but the parameter is declared `required: true` in the path; the optional form is the separate path of §2.3 |

Errors: as §2.3; the 404 description is only "cf. RFC 2616".

Fixtures: none published.

### 2.5 Download a return — `GET /telecharger-retour/1.0/{idFlux}/{idRetour}`

`operationId`: `telechargerRetourGet-v1`. PDF host `https://telechargement.dpae-edi.urssaf.fr` (port `8443` for
concentrators). Downloads the returns listed by §2.3 / §2.4 ("URL permettant le téléchargement" = the `url` field).

Header: `Authorization: DPAELogin jeton=…` (required).

| Field | Type | Req. | Rules |
|---|---|---|---|
| `idFlux` (path) | string | yes | Flow identifier, 1–50 chars (`flux.id`) |
| `idRetour` (path) | string | yes | Return identifier, 1–10 chars (`retour.id`) |

Response 200: content of the return "dans son format d'origine", format given by the response `Content-Type`
(`application/xml` or `application/pdf` per `produces`). The Swagger types it as `envoi` (§2.2), which only fits the
XML returns. Content of each return nature (10, 11, 20, 41): formats not provided (see §3.1).

Errors: 401 (§1.3); **403** "Le déclarant ou le concentrateur tente de télécharger un retour qui porte sur une DPAE
qu'il n'a pas déposée"; **404** "Identifiant du retour inconnu (également après la purge du retour)"; 406; 415;
422 "La requête n'a pas pu être traitée, car les paramètres sont invalides".

Fixtures: none published.

---

## 3. Reference values

### 3.1 Return types and codes (NAT/5492 "Disponibilité des retours par environnements")

Origin of all returns: `SI DPAE`.

| Type | Code (`nature`) | Returns | "Test" | "Réel" |
|---|---|---|---|---|
| AR | `10` | AEE (*accusé d'enregistrement électronique*), Avis de rejet | yes | yes |
| CR | `11` | Certificat de conformité, Bilan d'anomalies | yes | yes |
| ~AR | `20` | Bilan d'Identification des Salariés (BIS) | no | yes |
| CR | `41` | Certificat de conformité des DPAE (= the DPAE *accusé de réception*, a.k.a. conformity certificate) | no | yes |

Details (NAT/5491, NAT/5493):

- Formats `10` and `11` are the DSN ones; `20` and `41` are DPAE-specific and unchanged from the pre-API offer.
- DPAE file format `120` (TXT or XML), `120` also for the AR (*accusé de réception*), BIS format "Doc v 1.3".
- Return `41` carries the fields `C_RET_AR_LOT` and `C_RETOUR_AR` (FAQ). A correctly recorded DPAE always returns a
  case reference in the field `R_DOSSIER`.
- Return `20` (BIS): produced only on a divergence with the SNGI (*Système National de Gestion des Identifiants*);
  never in the absence of anomaly. It is not an accusé de réception: **do not redeclare** the employees to fix
  recorded DPAE.
- Return scenarios after a deposit (FAQ): (1) ARE; (2) AEE + BAN; (3) AEE + (BAN and/or CCO) + DPAE;
  (4) AEE + (BAN and/or CCO) + DPAE + BIS. Abbreviations: ARE *Avis de REjet*, AEE *Accusé de rEception
  Electronique*, BAN *Bilan d'ANomalie*, CCO *Certificat de COnformité*, BIS *Bilan d'Identification d'un Salarié*.
  In one file a DPAE can be conformant while another is not.
- Conformity certificates and anomaly reports are grouped in one file matching the initial file, structured by
  establishment (one report per assignment SIRET). Only the faulty DPAE is rejected, not the whole file (FAQ).

### 3.2 Return statuses (`statut`)

| Value | Meaning |
|---|---|
| `OK` | Business processing succeeded at this step (Swagger example) |
| `KO` | Failed (may come without document) |
| `ANO` | Anomaly |
| `EN_COURS` | In progress |

Only the enum and the sentence "Indique le résultat du traitement métier à cette étape" are published; the labels
above are *inferred from the value names*.

### 3.3 Test indicator

| Source | Statement |
|---|---|
| NAT/5491 | Field `C_VERSION` "doit contenir la (ou les) version(s) de la norme applicable(s) au moment du dépôt : `120` ou `TST` pour test, échec sinon" |
| NAT/5492 example | XML element `FR_DUE_Upload.Test.Indicator` contains `120` |
| NAT/5492 table | Returns `20` and `41` are available in "réel" mode only |

That `FR_DUE_Upload.Test.Indicator` is the XML counterpart of `C_VERSION` (value `120` = real, `TST` = test) is
*inferred from the example*. The full set of accepted values is not published. The same hosts serve both modes.

### 3.4 Norm control codes (NAT/5491 "Contrôles de norme", brick "contrôle" block 1)

Applied to employer, employee and contract data; the code is the error code of the DPAE return.

| Data | Rule | Code |
|---|---|---|
| Employer SIRET | mandatory | not published |
| URSSAF code | mandatory; length < 4; must be referenced in the URSSAF table | `02` |
| NAF code | mandatory; length 4 or 5; referenced in the SI DPAE; contract CTT requires `7820Z` (code `45`) | `03` |
| Raison sociale 1 | mandatory; ≤ 32 chars; allowed `a-zA-Z0-9éèêë âàä öô ûü îï ç°² !#$%&'()*+,-./:;<=>?@` | `05` |
| Employer address 1 | mandatory; ≤ 32 chars; same allowed set | `06` |
| Postal code | mandatory; digits only; length 5; `00000` does not exist | `07` |
| Commune | mandatory; ≤ 32 chars; same allowed set | `08` |
| Raison sociale 2, address 2, phone | optional; ≤ 32 chars; phone 10 digits | `99` |
| Employee name | mandatory; ≤ 32 chars; allowed `A-Z' .&-` | `31` |
| Employee first name | mandatory; ≤ 32 chars; allowed `A-Z' .&-` | `32` |
| Birth date | mandatory, valid, before today | `33` |
| Birth place | mandatory; ≤ 32 chars; allowed `A-Z0-9 .'-` | `34` |
| Hire date | mandatory, valid, within one year in the past and one year in the future; hour 0–23, minute 0–59 | `35` |
| NIR | length 13 or 15; valid against sex and birth date; key valid if 15 chars | `38` |
| Contract type | mandatory; `1` (CDD), `2` (CDI), `3` (CTT) | `44` |
| Contract type vs NAF | CTT requires NAF `7820Z` | `45` |
| End of CDD | valid; ≥ hire date; time initialised to `23:59:59` | `46` |
| Birth department | mandatory | `47` |
| Health service (*service de santé au travail*) | mandatory except for CTT; referenced in the SI DPAE or internal medicine `MT01` / `MT999` | `50` |
| Trial period | ≤ 3 characters, digits | `99` |

### 3.5 Business results (block 2, NAT/5491)

| Code | Meaning |
|---|---|
| `00` | Message processed correctly |
| `98` | Duplicate DPAE detected: the DPAE is rejected; "si les éléments fournis dans la DPAE initiale conviennent, aucune action n'est à opérer" |
| `99` | DPAE taken into account but one or more **optional** fields contained errors; a case reference (`R_DOSSIER`) is set |

### 3.6 Pre-control and SIRET control messages (NAT/5491)

| Code | Label |
|---|---|
| `B1-104-02` | La transmission comporte une archive XXXXXX trop volumineuse |
| `B1-104-03` | La transmission comporte une archive XXXXXX de taille nulle |
| `B1-104-04` | La transmission comporte une archive XXXXXX inexploitable |
| `B1-104-05` | La transmission comporte plus d'un niveau d'imbrication d'archives |
| `B1-104-06` | La transmission comporte un fichier XXXXXX de taille nulle |
| `B1-104-07` | La transmission comporte un fichier XXXXXX trop volumineux |
| `B1-105-10` … `B1-105-15` | Le format du fichier XXXXXXX n'est pas reconnu (six codes with an identical label, to treat the cases separately) |
| `B1-201-01` | Siret XXXXXX inconnu |
| `B1-208-01` | Siret XXXXXX radié au JJ/MM/AAAA |

Employer SIRETs in the flow must exist in the establishment repository with no cessation date; the declarant SIRET is
checked at registration and at authentication.

---

## 4. Package decisions derived from the specification

Every decision below is recorded in an accepted ADR of [docs/adr/](adr/) (2026-11-22), which holds the details and the
section references; this list is the summary.

1. **No OAuth2** ([ADR 0001](adr/0001-xml-token-authentication.md)). `DpaeTokenProvider` posts the XML
   `identifiants` (root `identifiants`, no namespace, Swagger property order, encoding configurable, default `UTF-8`)
   and keeps the base-64 answer as an opaque `DpaeToken`, **in process memory only** (`InMemoryTokenStore`, no
   persistent or shared store). No proactive expiry; a 401 `Expiration du jeton` drops the token, re-authenticates and
   replays the call once; `Jeton manquant ou invalide` drops the token and throws. Every 401 is mapped by realm
   (§1.3) to `AuthenticationFailure` (`PasswordExpired`, `InvalidCredentials`, `NotRegistered`, `TokenInvalid`,
   `TokenExpired`, `Unknown`); credential refusals park the tenant's records in `invalid_credentials`. Local
   credential checks are the Swagger lengths only (the accent-less name pattern is not applied, §5.4). The realm is
   read from `WWW-Authenticate` between `"…"` or `«…»` (UTF-8 or ISO-8859-1 bytes). The token is cached under an
   opaque key hashing tenant, authentication base URI, SIRET and service code, never the password.
2. **Hosts** ([ADR 0002](adr/0002-hosts-per-service.md)). `Environment` (and `Sandbox`) is removed. Four base URIs
   (authentication `https://mon.urssaf.fr`, deposit `https://depot.dpae-edi.urssaf.fr`, consultation
   `https://consultation.dpae-edi.urssaf.fr`, download `https://telechargement.dpae-edi.urssaf.fr`), each overridable
   from configuration and `%env()%`, authentication included; a base URI may carry a path prefix, which keeps the
   Swagger reading expressible without a dedicated switch. `UrssafEndpoints` loses the `/authentifier_dpae` prefix
   (only the authentication path is `/authentifier_dpae`); the deposit path is `/deposer-dsn/1.0` (Swagger). Port
   `concentrator_port` (default `8443`) is added to the **default** deposit, consultation and download URIs for a
   `98` identity, never to an overridden URI nor to authentication. The `url` of a `retour` is honoured **only if its
   origin (scheme, host, effective port) equals the configured download origin**; otherwise the download is rebuilt
   from `idFlux` / `idRetour`, or fails with `ReturnNotDownloadable` without `retour.id`. Redirects are never
   followed. A base URI or a return `url` carrying credentials (`user@`), whitespace, a control character, a
   backslash or port `0` is refused (`WebUri`): `https://good-host@evil/` is never on the origin.
3. **Test vs real is a payload concern** ([ADR 0003](adr/0003-declaration-mode-and-simulator-guard.md)).
   `DeclarationMode` (`Test` | `Real`) is the package's notion of "production". The installation mode
   `declaration_mode` (default `test`, never `real` by default) caps the required per-deposit mode (`Real` deposit in a
   `test` installation → `declaration_mode.not_allowed`). Indicator values are configurable (defaults `Real` → `120`,
   `Test` → `TST`, inferred §3.3). A raw XML flow whose `FR_DUE_Upload.Test.Indicator` does not match the deposit mode
   is refused, never rewritten; flat flows are not accepted in this version (layout not provided). The simulator is
   refused with `real`: `SimulatedGatewayFactory` (runtime, resolved value), build-time literal check,
   `withGateway()` of the standalone builder, and `SimulatedDpaeGateway` itself. Returns 20 and 41 are never expected
   in test mode.
4. **Declaration model** ([ADR 0007](adr/0007-declaration-model.md)). Scope of the first version: **phase 1 + the
   declaration model**. Two inputs reach the same `DepositDpae`: a raw XML flow (`DpaeFlow::fromXml`, checked for
   well-formedness, no DOCTYPE, root `FR_DUE_Upload`, mode indicator; deposited verbatim) and a `Declaration` (one
   `Employer`, one `Employee`, one `Contract`) built by `DpaeFlowBuilder` in ISO-8859-1. The model holds only
   the elements of the official example (§2.2), validated with the §3.4 rules that need no unpublished table or
   algorithm (violation parameter `normCode`). The raison sociale 1 is modelled (optional, validated when given) but never
   written, no element being published; every declaration reports it as a warning. Not modelled (no element published):
   raison sociale 2, address 2, trial period. The employer SIRET must be 14 digits. NIR (length 13 or 15) and `Sex.Code` are optional, as in §3.4. Not checked locally: table
   memberships (`02`, `03`, `50`), NIR characters, consistency and key (`38`). The
   unpublished vocabulary is configurable (`ContractType` → `CDD` / `CDI` / `CTT` by default, `1` / `2` / `3` by
   configuration; employee name pattern default the strict `A-Z' .&-`, so the official `Alain` is rejected locally); `Sex.Code`, URSSAF code, health service and birth
   department stay raw strings.
5. **Request headers and encoding** ([ADR 0004](adr/0004-xml-gzip-client.md)). Deposit: `Authorization: DPAELogin
   jeton=…`, `Content-Type: text/plain` (configurable), `Content-Encoding: gzip`, body `gzencode()` of the unchanged
   flow bytes. Every request sends `Accept-Encoding: gzip`; a still-gzipped answer is decoded by the client
   (`gzdecode()` with a length limit), and every decoded answer is capped at `max_response_bytes` (package default
   against decompression bombs, `null` disables it). Whatever the PSR-18 client throws is a transport failure.
6. **Deposit result is a sum type** ([ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)):
   `ElectronicReceipt` (200) | `RejectionNotice` (422 with an XML body), each with nullable `FlowId`, declarant SIRET
   and generation date extracted from the Swagger `envoi` names, and the raw document kept. 400 and 404 / 406 / 415 /
   3xx → `failed` (not processed, not retried); transport error, timeout, 5xx, unreadable 200, non-XML 422 →
   `OutcomeUnknown` (`outcome_unknown`). Any other 2xx is read as a receipt, a 429 as `PollingConstraintViolated`, a failed
   token call (first or re-authentication) stays a retryable `UrssafUnavailable` because it proves nothing was sent.
7. **Unit deposit and size** ([ADR 0004](adr/0004-xml-gzip-client.md), [ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)).
   One flow per call; no split, merge or archive. Optional `max_flow_bytes` (default 100 000 000, the FAQ's 100 Mo)
   checked on the uncompressed flow. `DepositDpae` carries the host's `ExternalReference`; the `DpaeFlow` carries its
   required `DeclarationMode`. `(tenant, external reference)` and `(tenant, FlowFingerprint)` (SHA-256 of the
   uncompressed flow) are unique among open deposits: a repeated call returns the open deposit; a new deposit only once
   the previous one is closed (`failed`, `rejected`, `not_received`). Failures that prove nothing was sent (token call,
   `Jeton manquant ou invalide`, second `Expiration du jeton`) put the deposit back to `pending`.
8. **Unknown outcome** ([ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)). Never resent blindly. Key:
   declarant SIRET + flow fingerprint + publication window. The fingerprint identifies the local deposit only; the
   candidates are the locally unknown `flux.id` with a nature `10` return published in `[claimedAt − skew, claimedAt
   + window]`; the declarant SIRET (tenant SIRET for `25`, flow employer SIRET for `98`) filters them through the
   downloaded `envoi_identification.declarant.siret`. Exactly one candidate is adopted, and only when no other deposit
   of the tenant without a known flow id covers it; otherwise manual review, then `ResolveDepositOutcome`. Receipts
   and rejection notices whose `IdFluxDPAE` could not be read get their flow id the same way. **Lot 5 does not
   download the nature `10` document**, so the declarant SIRET is not compared (§5.23); the status (`OK` / `KO`) settles
   an unknown outcome, any other status leaves it unknown.
9. **Polling** ([ADR 0006](adr/0006-polling-constraints.md)). One-date path when `to` is null, two-date path
   otherwise; `yyyyMMddHHmmss` (24-hour reading also for `publication` / `production`), zone `returns_time_zone`
   (default `Europe/Paris`). 422 and 429 on lists → `PollingConstraintViolated` (`RetryableException`, delay from
   `Retry-After` if any, else policy; status kept). No local range limit by default (`polling_max_range` null);
   `SynchronizeReturns` keeps a dedicated per-tenant cursor (start of the last successful listing minus an overlap)
   and upserts returns on `(tenant, flux.id, nature, ops)`, an absent `ops` stored as the empty string.
10. **Download** ([ADR 0002](adr/0002-hosts-per-service.md), [ADR 0006](adr/0006-polling-constraints.md)). `url`
    policy of decision 2; raw bytes + `Content-Type` kept, typed parsing per nature added once samples exist; local
    checks `idFlux` 1–50, `idRetour` 1–10; 403 → `return.not_owned`, 404 → `ReturnNoLongerAvailable` (return marked
    `purged`).
11. **Persistence** ([ADR 0008](adr/0008-doctrine-persistence.md)). Doctrine stays optional. Tables
    `urssaf_dpae_deposits`, `urssaf_dpae_deposit_flows` (only with `keep_flows: true`), `urssaf_dpae_returns`,
    `urssaf_dpae_return_contents`, `urssaf_dpae_sync_cursors`, `urssaf_dpae_outbox`; DBAL types `urssaf_dpae_*`.
    Returns and contents are kept beyond the URSSAF 3-month purge; the package sets no local retention period and
    deletes nothing itself. Schema documented in `docs/doctrine.md`, **no migration shipped** (as the reference).
12. **Concentrators** ([ADR 0001](adr/0001-xml-token-authentication.md), [ADR 0002](adr/0002-hosts-per-service.md)).
    One tenant = one authenticating identity. `ServiceCode` keeps the raw integer next to `ServiceKind` (`Direct` `25`,
    `Concentrator` `98`); `97` and other values are sent as is and get no concentrator port.
13. **Secrets and personal data.** The password travels in clear in the XML body (TLS only) and is never logged or
    stored by the package; the consumer stores it encrypted (`CredentialsProvider`). NIR, names, birth data of the
    flows and returns are personal data: never logged, masked by `__debugInfo`; the deposited flow is not stored by
    default.
14. **XML handling** ([ADR 0004](adr/0004-xml-gzip-client.md)). `SafeXmlReader`: `LIBXML_NONET` only, documents with
    a DOCTYPE refused (answers and caller flows). Declared encoding honoured, then the `Content-Type` charset, then
    UTF-8. Built flows in ISO-8859-1. Bodies of non-2xx answers are discarded unread (except the deposit 422); only
    status, `WWW-Authenticate` and `Retry-After` are used.
15. **Coexistence** ([ADR 0009](adr/0009-coexistence.md)). Commands `urssaf:dpae:*`, env variables `URSSAF_DPAE_*`,
    Flex recipe in `recipe/`, prefixes `urssaf_dpae` for tables, DBAL types, locks, schedule, service ids, translation
    domain and (reserved) cache keys, events `urssaf.dpae.*`, `InMemoryCredentialsProvider`, Symfony
    `^6.4.3 || ^7.4 || ^8.0`.
16. **Spec fidelity.** Swagger and PDFs are both reproduced; where they disagree the PDFs prevail for addresses and
    the Swagger prevails for paths and field constraints, until the contract test (§5) settles each point. Every
    value inferred rather than published is configurable and listed in §5.
17. **Application layer as implemented** (lot 5, amendments of [ADR 0003](adr/0003-declaration-mode-and-simulator-guard.md),
    [0005](adr/0005-deposit-result-and-unknown-outcome.md), [0006](adr/0006-polling-constraints.md),
    [0008](adr/0008-doctrine-persistence.md)). Handlers are `final readonly`, invoked with a command, and share a
    `UnitOfWork` (transaction, integration events appended to the outbox inside it, domain events published after the
    commit; a failing listener is reported, never undoes the work).
    - `DepositDpaeHandler(DepositRepository, DpaeGateway, CredentialsProvider, Clock, IdGenerator, UnitOfWork, WorkflowPolicy, DeclarationMode $installationMode)`
      `__invoke(DepositDpae): Deposit`. Checks the installation ceiling first (`declaration_mode.not_allowed`), records the
      deposit in its own transaction (idempotence by reference and fingerprint, `deposit.reference_conflict`,
      `deposit.flow_already_open`), claims it atomically (`DepositRepository::claim()`), and only the claimant calls the
      gateway. A `pending` deposit found again is sent again by calling the handler with the same reference and flow (the
      flow is not stored). Outcomes: receipt → `received`, rejection notice → `rejected`; `OutcomeUnknown`, a transport
      error after the send and any `UrssafUnavailable::mayHaveReachedUrssaf()` → `outcome_unknown` (returned, never
      resent); credential refusal → `invalid_credentials` (returned); 400 and any other `UrssafException` (404, 406, 415,
      3xx) → `failed` (returned); a failure proving the flow was not sent (token refused after the replay, throttling,
      local refusal) → `pending` again or `failed` at `maxDepositAttempts`, and **rethrown**; any other `Throwable` →
      `outcome_unknown` and rethrown.
    - `ListReturnsHandler(...)` `__invoke(ListReturns): list<ReturnReference>`, `DownloadReturnHandler(...)`
      `__invoke(DownloadReturn): ReturnContent` (stored document first; 404 → `purged`, no document → `not_downloadable`,
      both saved then rethrown), `SynchronizeReturnsHandler(...)` `__invoke(SynchronizeReturns): SynchronizationReport`
      (a 422 / 429 is **reported** with `retryAfterSeconds` and `needsAttention`, not thrown, the cursor not moving),
      `ReconcileUnknownDepositsHandler(...)` `__invoke(ReconcileUnknownDeposits): ReconciliationReport`.
    - `Application\Port\Clock` is the application's own clock port (`SystemClock` implements it and PSR-20, `PsrClock`
      adapts any PSR-20 clock): the Application layer depends on the Domain and the Contract only.
    - The simulator is `Testing\SimulatedDpaeGateway`; `Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory::create(string
      $declarationMode, Clock, IdGenerator)` accepts only the exact value `test` (fail closed). The standalone entry point is
      `Standalone\UrssafDpaeBuilder` (`withGateway()` refuses a `real` installation) producing `Standalone\UrssafDpae`.
    - Not in lot 5 (kept in [roadmap.md](roadmap.md)): `DownloadDueReturns`, `ResolveDepositOutcome`,
      `RequeueInvalidCredentials`, `GetWorkflowOverview`, outbox relay and purge, the Symfony and Doctrine bridges. The
      domain transitions behind the first three exist (`Deposit::resolveAs*`, `requeue`).

---

## 5. Open points

1. **Hosts.** Swagger: `api.urssaf.fr` + basePath `/authentifier_dpae` (so e.g. `/authentifier_dpae/deposer-dsn/1.0`).
   PDF: four dedicated hosts, no base path, port `8443` for concentrators; the Swagger's own 404 examples show paths
   without base path. To confirm which one is live, and whether `api.urssaf.fr` is a gateway to the same services.
   Also: deposit path with or without the trailing slash of the PDF (`/deposer-dsn/1.0/`); origin of the real `url`
   values of the returns (the package follows only the configured download origin, ADR 0002); whether the port `8443`
   still applies. Handled by configuration (four overridable base URIs, `concentrator_port`).
2. **Test mode.** Exact values and semantics of `FR_DUE_Upload.Test.Indicator` versus `C_VERSION` (`120`, `TST`);
   whether a test deposit has side effects; no sandbox in the catalogue. The package defaults `Real` → `120`,
   `Test` → `TST` (configurable `test_indicator.*`, ADR 0003) and refuses flat flows because the position of
   `C_VERSION` is not published.
3. **Archives and multi-DPAE flows.** Swagger: unit deposit, one rank (S20 wording), archives refused. NAT/5491:
   archives (`gz`, `tar`, `zip`, `gzip`), multi-employer files, per-file pre-controls. NAT/5492: archives refused.
   FAQ: several hires for one establishment allowed, 100 Mo limit. To settle on the contract test.
4. **Authentication XML.** Root element name, namespace, element order of `identifiants`: no XML example; also `service`
   documented as `98` / `25` while the example is `97`; the name patterns in the Swagger lost their accents
   (`eeeeaaauuuiioocEEEE…`), the real character classes are unknown; `nom`/`prenom` examples (`John`/`Doe`) look
   swapped. The XML encoding of the body is not published either: configurable `authentication_encoding`, default
   `UTF-8` (ADR 0001). The pattern is not applied locally.
5. **Content types.** Deposit request type (PDF `text/plain`, Swagger none, flow is XML `ISO-8859-1`); Swagger global
   `application/json` is unused; Swagger `Content-Type` header on authentication optional.
6. **Token lifetime**, size and charset not published; re-authentication strategy is therefore reactive (§4.1).
7. **Quotas** and maximum polling range not published (429 vs 422 transition, §1.1).
8. **Date formats and time zone** of the list paths and of `publication` / `production`: `yyyyMMddHHmmss` for the
   paths, `yyyyMMddhhmmss` (12-hour letters) for the fields in the Swagger; zone unknown; whether `dateDebut` is
   inclusive. The package reads both with a 24-hour clock in `returns_time_zone` (default `Europe/Paris`) and keeps
   the raw strings (ADR 0006). The package defaults of the tunables that depend on these unknowns
   (`pollingOverlap`, `reconciliationSkew`, `reconciliationWindow`, `staleDepositAfter`, `manualReviewAfter`,
   `initialLookback`, `pollingRetryDelay`) are not specification values (ADR 0005, 0006); `pollingOverlap` defaults to
   `PT2H` (Paris is UTC+1 in winter, UTC+2 in summer) and `WorkflowPolicy` refuses non-positive durations and a negative skew.
9. **Return bodies.** AEE, rejection notice, anomaly report, conformity certificates, BIS: formats referenced
   (`dsn_bilans_v01r06.xsd`, BIS v1.3, AR format 120, DSN guide V3.5) but **not provided**; no official example
   (the Swagger "Exemple succès (AEE)" / "Exemple avis de rejet" links are empty). The `envoi` schema is weakly
   typed (`envoi_etat` typed as `retours`) and `url`/`id` wire names in XML are unknown. Also unknown: which SIRET the
   AEE triplet carries for a concentrator (the package expects the flow's employer SIRET, ADR 0005), and whether the
   `statut` of a nature `10` return is `OK` for an AEE and `KO` for a rejection notice (inferred, used only by the
   reconciliation).
10. **`nature` values.** Swagger example `50` versus documented `10`, `11`, `20`, `41`; labels of `ANO`, `EN_COURS`.
11. **Swagger text copied from the DSN.** The list operations describe *fiches de paramétrage* and `<declarant>`; the
    "S20" and `S21.G00.70.002` rubrics are DSN vocabulary. `dateFin` declared required but described as optional.
12. **XML value vocabulary** of the DPAE flow (`FR_Contract.Nature.Code`: example `CDI` versus norm codes `1`/`2`/`3`;
    `Sex.Code`, `FR_Employer.URSSAF.Code`, health service codes): the flow format (*Dessin d'enregistrement DPAE*) is
    not provided; the flat format (990 characters) layout is not provided either. Consequences for the declaration
   model (ADR 0007): raison sociale 1 is mandatory under §3.4 (code `05`) but has no element in the example, nor do
   raison sociale 2, address 2 and the trial period, so the builder cannot write them; whether a built flow without
   raison sociale is accepted must be checked by a test-mode deposit before real use. The example's first name
   `Alain` breaks the §3.4 set `A-Z' .&-` (pattern configurable). Contract-type wire values default to `CDD` / `CDI` /
   `CTT` (only `CDI` is attested), configurable to `1` / `2` / `3`.
    Local decisions of the declaration model (lot 2): the raison sociale is optional in the model, validated (≤ 32,
    §3.4 set, code `05`) when given, **never written**, and every `Declaration` reports it as a warning
    (`employer.company_name_missing` / `employer.company_name_not_serialized`); the default employee-name pattern is
    the strict `/^[A-Z' .&-]+$/D`, so the official first name `Alain` is **rejected locally by default**
    (`employee.first_name_invalid_characters`, code `32`): a host that wants the example to pass uses
    `DeclarationRules::of("/^[A-Za-z' .&-]+\$/D")` (the golden test and the official fixture do); an invalid optional
    phone (code `99`) is a warning, URSSAF reporting it as a business result (§3.5); the APE and URSSAF codes are
    trimmed and mandatory; the employer SIRET must be 14 digits; a 13-character NIR is written without
    `FR_NNI.NIRKey.Text`, a 15-character one is split 13 + 2 (both inferred); the reference day of "before today" /
    "within one year" is passed by the caller (no clock in the Domain), the year window being the same calendar day
    (29 February clamped to 28 February).
13. **Registration.** Catalogue: restricted access with a subscription form; FAQ: no specific registration for a
    company or third-party declarant, only for concentrators (by e-mail). To confirm what a package user must obtain.
14. **Error payload** for 400 (deposit) is undocumented; only the HTML pages are shown. The package reads a 400 as
    "not processed" (`failed`, never retried automatically, ADR 0005); whether URSSAF may still record the flow is
    unknown.
15. **Documentation date.** PDFs date from October 2021 while the guide revision history stops at V1.1 (2017) and
    NAT/5491 mentions a deployment "prévue à la fin du mois de septembre 2019": the documents may be outdated.
16. **Maximum size** "paramétrable" (NAT/5491) versus 100 Mo (FAQ); compressed or not is unknown. The optional local
    limit `max_flow_bytes` is checked on the uncompressed flow (ADR 0004).
17. **List 422.** On the list operations 422 is both "paramètres invalides" (§2.3) and the temporary form of the
    polling-constraint 429 (§1.1); the package maps both to the retryable `PollingConstraintViolated` and keeps the
    status (ADR 0006). No `Retry-After` header is published.
18. **Inferred XML form of `retours`** (fixtures `tests/Fixtures/wire/retours_*.xml` are fictitious): root `retours`,
    `flux` elements holding `retour` elements, every field (Swagger names) read as a child element or as an attribute
    under any namespace; a `retour` without an integer `nature` (an optional `+` sign is refused), `statut`,
    `publication` or `production`, or a `flux` holding a `retour` without a usable `id`, is refused rather than skipped;
    a `flux` without any `retour` is skipped (`RetoursXmlReader`, ADR 0004). The raw XML of a listing is **not kept**:
    `DpaeGateway::listReturns()` returns `list<ReturnReference>`, whose raw values (`publicationRaw`, `productionRaw`,
    status code) already preserve what was read (ADR 0006 amendment).
19. **Inferred XML form of `envoi`** (fixtures `envoi_*.xml` are fictitious): flow id `IdFluxDPAE` (else the `id` of the
    first `flux`), `siret` (Swagger `declarant.siret`, looked up under `declarant` first, then anywhere), generation
    date `DateGeneration` / `dateGeneration` (the `publication` of a `retour` is not one), reject reasons `motif` /
    `motifRejet` / `MotifRejet`; element or attribute, each nullable. The inferred names are those of `EnvoiVocabulary`,
    which a host overrides (`EnvoiXmlReader`, ADR 0005).
20. **Date reading.** `publication`, `production` and the generation date are read as `yyyyMMddHHmmss` (separators,
    fraction and an explicit offset tolerated) in `returns_time_zone`; an impossible date or a clock hour skipped by a
    time change gives a null instant and keeps the raw string (`WireDate`, ADR 0006).
21. **Authentication body written in UTF-8 by default**: a character the declared encoding lacks is written as a numeric
    character reference, a character XML 1.0 cannot carry is refused (`IdentifiantsXmlWriter`, same inferred shape as
    §5.4).
22. **Defaults of the HTTP gateway** (lot 4, not specification values): `max_response_bytes` `100000000` (decoded answers;
    `null` disables it), `User-Agent: thebeardedbear-urssaf-dpae`, no `Accept` header, `Content-Type: application/xml`
    for the authentication body. A 429 on the authentication call is read as a transient failure
    (`UrssafUnavailable`, retryable, call not sent) rather than as an unexpected 4xx, since it is not published and a
    quota is the likely cause (ADR 0001 amendment). A 2xx other than 200 on the deposit is read as a receipt. The
    time zone of `Retry-After` HTTP dates is GMT as RFC 9110 requires; no `Retry-After` is published for this API (§1.1).
    A 429 on the deposit and on the download (not published for them: the Swagger lists it for the lists only) is read
    like a list one, as `PollingConstraintViolated` (retryable, never an unknown outcome since nothing was processed).
    A base URI may use `http` only for a mock server: `http` is refused (`base_uri.insecure_scheme`) for the four published
    URSSAF hosts. `declaration_mode` (ceiling of the installation, default `test`, ADR 0003 amendment): a `real` flow with
    a `test` ceiling is refused before any call (`declaration_mode.not_allowed`). The PSR-18 client must not follow
    redirects (a 307 / 308 would resend the deposit); transport exceptions are never chained (class name only).
23. **Reconciliation without the nature `10` document** (lot 5, [ADR 0005](adr/0005-deposit-result-and-unknown-outcome.md)
    amendment). `ReconcileUnknownDepositsHandler` adopts a flux from the return list alone (sole nature `10` candidate in the
    claim window, no competing deposit, status `OK` / `KO`); the declarant SIRET of the downloaded AEE is not compared,
    because the `envoi` schema is unpublished (§5.9) and the Application layer has no reader for it. A flux that another
    channel deposited in the same window would be adopted if it is the only one. For a concentrator the deposit does not
    know its declarant SIRET (the flow's employer SIRET is not read), so the comparison could not be made anyway. To be
    closed with the contract-test samples of the AEE.
24. **Doctrine bridge** (lot 7, [ADR 0008](adr/0008-doctrine-persistence.md) amendments, [docs/doctrine.md](doctrine.md)).
    Where the schema departs from the ADR table, because the aggregates dictate it: `nature` / `statut` are stored raw
    only (the parsed values are derived by the domain); `ops` is a non-null string with a `has_ops` flag, so an absent
    `ops` stays different from an empty one; the answer of URSSAF is one JSON `result` column (kind, flow id, SIRET,
    instant, reasons, raw document in base 64), there is no `size` column and an `adopted` column is added; a return has a
    derived key (SHA-256 of tenant, flux, nature, ops) that its stored content shares; `urssaf_dpae_deposit_flows` is
    written only through `DoctrineDepositFlowStore`, which the package never calls. A unique violation on a deposit save is
    reported as `deposit.reference_conflict`, `deposit.flow_already_open` or `deposit.concurrent_modification` (existing
    codes, no new one). The functional tests run on SQLite only: MySQL, MariaDB and PostgreSQL are not exercised, the
    statements stay portable and the unique indexes never rely on a partial index (SQL Server treats `NULL`s as equal in a
    unique index and is not supported). The retention period of the copy of the host (the specification publishes only the
    3 months of URSSAF and the 13 months of the consultation service, §1.2) is the host's: the package only offers the
    `delete…Before()` building blocks.
25. **Symfony bridge** (lot 6, [docs/symfony.md](symfony.md), [ADR 0009](adr/0009-coexistence.md) amendment). Delivered; the outbox
    relay and purge and the `IntegrationEventSink` port are now delivered too. Still deferred: `DownloadDueReturns`,
    `ResolveDepositOutcome`, `RequeueInvalidCredentials`, `GetWorkflowOverview` (so no `status`, `resolve`, `requeue`,
    `download-returns` command). Symfony 8 is exercised in CI (PHP 8.4, Symfony 8.1, DoctrineBundle 3.4: unit and functional suites,
    ADR 0009); the static analysis and the QA tools stay on the locked PHP 8.2 dependencies, as some pin `symfony/console` below 8.
