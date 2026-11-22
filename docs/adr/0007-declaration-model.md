# ADR 0007 — Declaration model limited to the official example

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0003](0003-declaration-mode-and-simulator-guard.md),
  [ADR 0004](0004-xml-gzip-client.md)

## Context

- The deposited flow is a business payload of this package; its full format (*Dessin d'enregistrement DPAE*) is **not
  provided**. The only published XML is the NAT/5492 example ([api-reference.md](../api-reference.md) §2.2, fixture
  `tests/Fixtures/wire/deposit_request_minimal.xml`).
- The norm controls of NAT/5491 list rules and error codes for employer, employee and contract data (§3.4), some of
  which have **no element** in the example: raison sociale 1 (mandatory, code `05`), raison sociale 2 and address 2
  (optional, `99`), trial period (`99`).
- The XML value vocabulary is not published: `FR_Contract.Nature.Code` is `CDI` in the example while the norm
  controls give `1` (CDD), `2` (CDI), `3` (CTT); `Sex.Code`, URSSAF codes and health-service codes are not listed
  (§2.2, §5.12). The example's first name `Alain` is mixed case while the norm allows `A-Z' .&-` (§3.4).
- Unit deposit: one flow, one rank (Swagger); the FAQ allows several hires for one establishment (§1.2, §5.3).
- The former wording of [api-reference.md](../api-reference.md) §4.4 and the roadmap put the model in a later phase; ADR 0000 brought it
  into the first version. The scope chosen for the first version is **"Phase 1 + declaration model"**: the raw-flow
  deposit and a limited model with its builder.

## Decision

### Two inputs, one deposit

- `Domain\Declaration\DpaeFlow` is what is deposited, created either:
  - `DpaeFlow::fromXml(string $bytes, DeclarationMode $mode)` (raw flow, phase 1): well-formed, no DOCTYPE, root
    `FR_DUE_Upload`, indicator checked against `$mode` ([ADR 0003](0003-declaration-mode-and-simulator-guard.md),
    [ADR 0004](0004-xml-gzip-client.md)); nothing else is validated, the bytes are deposited verbatim; or
  - `Infrastructure\Urssaf\Wire\DeclarationXmlWriter::write(Declaration, DeclarationMode): DpaeFlow` (built flow).
- Either way the `DpaeFlow` carries its `DeclarationMode`, which cannot be omitted
  ([ADR 0003](0003-declaration-mode-and-simulator-guard.md)). Both go through the same `DepositDpae` use case. Anything the model cannot express is deposited as a raw flow.

### Model: the intersection of the example and §3.4

`Domain\Declaration\Declaration` = one `Employer`, one `Employee`, one `Contract` (one hire per flow, the Swagger unit
deposit). Only elements present in the example are modelled; each is written under the element name of the example,
in the example's order and namespaces (`rxorg`, `rxpadr`, `rxpers`, `rxphadr`, plus `cct`, `rxdt`, `xsi` declared on
the root as in the example).

| Model field | Element of the example | Local rule (source) | Code |
|---|---|---|---|
| `Employer::siret` | `rxorg:FR_Organization.SIRET.Identifier` | mandatory (§3.4); 14 digits (inferred from the example, Swagger `siret` 14) | — |
| `Employer::apeCode` | `rxorg:FR_Organization.APE.Code` | mandatory, length 4 or 5 (§3.4) | `03` |
| `Employer::urssafCode` | `FR_Employer.URSSAF.Code` | mandatory, length < 4 (§3.4) | `02` |
| `Employer::street` | `rxpadr:FR_PostalAddress.StreetDesignation.Text` | mandatory, ≤ 32, employer set (§3.4 "address 1") | `06` |
| `Employer::town` | `rxpadr:FR_PostalAddress.Town.Text` | mandatory, ≤ 32, employer set (§3.4 "commune") | `08` |
| `Employer::postalCode` | `rxpadr:FR_PostalAddress.Postal.Code` | mandatory, 5 digits, not `00000` (§3.4) | `07` |
| `Employer::phone` (optional) | `rxphadr:FR_PhoneAddress.PhoneNumber.Text` | 10 digits (§3.4) | `99` |
| `Employee::surname` | `rxpers:FR_PersonIdentity.Surname.Text` | mandatory, ≤ 32, employee name set (§3.4) | `31` |
| `Employee::firstName` | `rxpers:FR_PersonIdentity.ChristianName.Text` | mandatory, ≤ 32, employee name set (§3.4) | `32` |
| `Employee::sexCode` (optional) | `rxpers:FR_PersonIdentity.Sex.Code` | none in §3.4; raw string, vocabulary not published | — |
| `Employee::nir` (optional) | `rxpers:FR_NNI.NIR.Identifier` + `rxpers:FR_NNI.NIRKey.Text` | length 13 or 15 (§3.4), characters not checked; the first 13 characters go to `NIR.Identifier`, the last 2 of a 15-character value to `NIRKey.Text` (split inferred from the example, `1000000000000` / `99`) | `38` |
| `Employee::birthDate` | `rxpers:FR_Birth.Date` (`Y-m-d`) | mandatory, valid, before today (§3.4) | `33` |
| `Employee::birthTown` | `rxpers:FR_Birth.Town.Text` | mandatory, ≤ 32, `A-Z0-9 .'-` (§3.4) | `34` |
| `Employee::birthDepartment` | `FR_EmployeeComplement.Birth_Department.Code` | mandatory (§3.4); raw string | `47` |
| `Contract::start` | `FR_Contract.StartContract.Date` + `.Time` (`H:i:s`) | mandatory, within one year before / after today (§3.4) | `35` |
| `Contract::endDate` (optional) | `FR_Contract.EndContract.Date` | ≥ start date (§3.4 "end of CDD") | `46` |
| `Contract::type` | `FR_Contract.Nature.Code` | mandatory, `ContractType` `Cdd`/`Cdi`/`Ctt` (§3.4) | `44` |
| `Contract::healthService` | `FR_Contract.HealthService.Text` | mandatory except for CTT (§3.4); raw string | `50` |
| — | `FR_DUE_Upload.Test.Indicator` | from the deposit mode ([ADR 0003](0003-declaration-mode-and-simulator-guard.md)) | — |

Cross-field rule: `Ctt` requires `apeCode` `7820Z` (§3.4, code `45`). Character sets are those of §3.4, verbatim; the
employer set is `a-zA-Z0-9éèêë âàä öô ûü îï ç°² !#$%&'()*+,-./:;<=>?@`.

- Validation follows the notification pattern: every violation is collected and thrown at once as `ValidationFailed`.
  Violation codes are the package's (`employee.surname_too_long`, `contract.ctt_requires_naf_7820z`…); the §3.4 code is
  a parameter (`normCode`) so that a host can relate a local refusal to the code URSSAF would return.
- **Not checked locally** (the rule needs a table or an algorithm that is not published): membership of the URSSAF
  code, NAF and health service in the URSSAF / SI DPAE tables (`02`, `03`, `50`), NIR consistency with sex and birth
  date and NIR key validity (`38`; the official example `1000000000000` / `99` would fail a local key check). The
  hour 0–23 / minute 0–59 rule of `35` holds by construction (`Contract::start` is a `DateTimeImmutable`). URSSAF
  applies them and reports them in the returns.
- **Not modelled** (no element in the example): raison sociale 1 and 2, address 2, trial period, end-of-contract time
  (set to `23:59:59` by URSSAF, §3.4). Raison sociale 1 is mandatory under §3.4 (code `05`): whether a built flow
  without it is accepted is an open point (§5.12) to be settled by a test-mode contract deposit **before** the builder
  is used in real mode. Until then the README states the limit and the raw-flow deposit remains the way to send any
  element outside the model. No free-form extension element is offered: element names would be invented.

### Unpublished vocabulary: preserved and configurable

- `Infrastructure\Urssaf\Wire\DeclarationVocabulary` maps the enums of the model to wire values. Defaults:
  `ContractType::Cdi` → `CDI` (the example), `Cdd` → `CDD`, `Ctt` → `CTT` (the same mnemonic form, **inferred**);
  the norm-control form `1` / `2` / `3` is one configuration away (`declaration_vocabulary.contract_type`).
- Values with no published list (`sexCode`, `urssafCode`, `healthService`, `birthDepartment`) are raw strings checked
  only by the §3.4 rule of their row; they are written as given.
- Name character sets: the §3.4 sets are the default (`A-Z' .&-` for employee names, so `Alain` is refused locally);
  `declaration_rules.employee_name_pattern` overrides it because the official example disagrees (§5.12). Letters are
  never transliterated nor upper-cased by the package (ADR 0000 "ASCII transliteration").
- The writer emits ISO-8859-1 with `<?xml version="1.0" encoding="ISO-8859-1" ?>` as in the example
  ([ADR 0004](0004-xml-gzip-client.md)); every §3.4 character set fits in ISO-8859-1.

## Consequences

- The writer's golden test is the official example: the model filled with the example's values (and the relaxed
  name pattern) must produce the fixture's elements and values in the same order.
- NIR, names and birth data live in `Employee`, masked by `__debugInfo` and never logged
  ([ADR 0000](0000-reference-architecture.md) §3).
- This ADR supersedes "later phase" in [api-reference.md](../api-reference.md) §4.4 and [roadmap.md](../roadmap.md).
  New elements, the flat format or several hires per flow are added once the *Dessin d'enregistrement DPAE* is
  published, each with an amendment.
