# ADR 0003 — `DeclarationMode` and simulator guard

- Status: Accepted
- Date: 2026-11-22
- Relates to: [ADR 0000](0000-reference-architecture.md), [ADR 0002](0002-hosts-per-service.md),
  [ADR 0007](0007-declaration-model.md), [ADR 0009](0009-coexistence.md)

## Context

In the reference, the target environment (sandbox or production) is the safety switch: the simulated gateway is
refused in production, at build time for a literal value and at runtime through `SimulatedGatewayFactory` (CESU model)
for a placeholder; the standalone builder's `withGateway()` refuses production (PAJE / TDAE model).

The DPAE has no sandbox host ([ADR 0002](0002-hosts-per-service.md)). Test versus real is a field of the deposited
flow ([api-reference.md](../api-reference.md) §1 "Test vs real", §3.3):

- NAT/5491: `C_VERSION` "doit contenir la (ou les) version(s) de la norme applicable(s) au moment du dépôt : `120` ou
  `TST` pour test, échec sinon" (§2.2 pre-controls, §3.3);
- NAT/5492 example: `FR_DUE_Upload.Test.Indicator` contains `120` (§2.2, fixture `deposit_request_minimal.xml`);
- that the XML element is the counterpart of `C_VERSION`, with `120` = real and `TST` = test, is **inferred**; the full
  set of values is not published (§3.3, §5.2);
- returns `20` and `41` exist in real mode only (§1.2, §3.1);
- the layout of the flat format (990 characters) is not provided, so the position of `C_VERSION` in it is unknown
  (§1.2, §5.12).

A real DPAE has legal effects (it declares a hire). Sending one by mistake from a test installation, or from the
simulator configuration, must be impossible.

## Decision

### The mode

- `Domain\Declaration\DeclarationMode`: backed enum `Test` (`test`) / `Real` (`real`). It is what the other packages
  call the environment: "production" means `DeclarationMode::Real` everywhere in this package (guards, messages,
  documentation). There is no other environment option.
- Installation mode: the configuration key `declaration_mode` (`test` | `real`, env `URSSAF_DPAE_DECLARATION_MODE` in
  the recipe, [ADR 0009](0009-coexistence.md)) is the **highest** mode the installation may send. Its default is
  `test` (bundle default and recipe value); `real` is never a default, anywhere.
- Deposit mode: the mode belongs to the flow. `Domain\Declaration\DpaeFlow` is only created with a `DeclarationMode`
  (`DpaeFlow::fromXml($bytes, $mode)` or the builder's `write($declaration, $mode)`, [ADR 0007](0007-declaration-model.md)),
  so `DepositDpae` and `DpaeGateway::deposit(TenantId, DpaeFlow)` carry it without a separate argument. Every entry
  point (console command `--mode`, Messenger message, standalone facade) requires it; no constructor or factory
  defaults it. A `Real` deposit with a
  `Test` installation is refused before any claim or HTTP call (`InvariantViolation`, code
  `declaration_mode.not_allowed`). A `Test` deposit is allowed in a `real` installation (contract checks of a live
  setup).
- Indicator vocabulary: `ApiConfiguration::testIndicator(DeclarationMode)` with defaults `Real` → `120`, `Test` →
  `TST`, both overridable (`test_indicator.real`, `test_indicator.test`) because the mapping is inferred (§5.2).

### The mode in the flow

- Built flows ([ADR 0007](0007-declaration-model.md)): the builder writes `FR_DUE_Upload.Test.Indicator` from the
  deposit mode through the vocabulary above.
- Raw XML flows: the package reads `FR_DUE_Upload.Test.Indicator` (child of the root `FR_DUE_Upload`, as in the
  example) and **refuses** the deposit (`ValidationFailed`, code `flow.declaration_mode_mismatch`) when the element is
  missing or its value is not the indicator of the deposit mode. The flow is never rewritten: what is sent is exactly
  what the caller gave.
- Flat flows are **not accepted** in this version (`ValidationFailed`, code `flow.format_not_supported`): their layout
  is not provided, so the package cannot check the mode it would send. This is a scope decision of the package, not a
  rule of the specification (both formats are accepted by URSSAF, §1.2); it is lifted when the layout is published
  (§5.12).

### Simulator guard

- `Testing\SimulatedDpaeGateway` (in-memory URSSAF, [ADR 0000](0000-reference-architecture.md) §11) itself refuses a
  `Real` deposit (`LogicException`), and never produces returns `20` / `41`.
- Symfony bridge, build time: `gateway.simulated: true` with a literal `declaration_mode: real` is a configuration
  error.
- Symfony bridge, runtime: the simulated gateway service is created by
  `Bridge\Symfony\DependencyInjection\SimulatedGatewayFactory::create(string $declarationMode)` (CESU model), which
  receives the **resolved** `declaration_mode` (so an `%env()%` placeholder is checked once its value is known),
  resolves it with `DeclarationMode::from()` and throws `LogicException` for `Real`.
- Standalone: `UrssafDpaeBuilder::withGateway(DpaeGateway $gateway)` throws `LogicException` when the builder's
  `declaration_mode` is `Real` (PAJE / TDAE model): only the HTTP gateway may talk in a real installation.
- Contract tests always deposit with `DeclarationMode::Test` and a `test` installation; the contract test that checks
  the indicator values settles §5.2.

## Consequences

- A real DPAE needs two explicit choices: `declaration_mode: real` in the configuration **and** `DeclarationMode::Real`
  on the deposit. Neither is ever implied.
- The simulator cannot run in a real installation, whatever the gateway wiring.
- Users with a flat-format generator must convert to XML or wait for the flat layout; this restriction is listed in
  §5.12 and the README.
- This ADR adapts ADR 0000 ("simulator refused with the real mode instead of the production environment"): the
  refusal is enforced both by installation mode and by deposit mode.

## Amendment 2026-11-22 — the mode check lives in the Domain

Replaces the "raw XML flows" bullet and the factory signature of the "mode in the flow" section.

- **Where.** The check is made by `Domain\Declaration\DpaeFlow` itself, not by the application layer:
  `DpaeFlow::fromXml(string $bytes, DeclarationMode $mode, TestIndicators $indicators)`. A `DpaeFlow` that exists is
  therefore always consistent with its mode.
- **`Domain\Declaration\TestIndicators`** holds the expected `FR_DUE_Upload.Test.Indicator` per mode: `Real` → `120`,
  `Test` → `TST` by default (`TestIndicators::defaults()`), configurable with `TestIndicators::of($real, $test)`
  (fed by `ApiConfiguration::testIndicator()`). The values are inferred from the `C_VERSION` rule (§3.3) and stay an
  open point (§5.2). The two values must be non-blank and different, otherwise the guard would be void.
- **Comparison.** Strict string equality with the text content of the `FR_DUE_Upload.Test.Indicator` **child of the
  root** (no trimming, case-sensitive, nested or prefixed elements ignored). A missing element, or any other value,
  is `ValidationFailed` with code `flow.declaration_mode_mismatch` (path `flow`, parameter `mode`). The official
  example (`120`) is a `Real` flow; it is refused in `Test` mode.
- **Flat flows.** An input whose first significant byte (after an optional UTF-8 BOM and XML whitespace) is not `<` is
  not an XML flow: `flow.format_not_supported`, distinct from `flow.not_well_formed` (XML that does not parse). A
  blank input stays `flow.empty`. A UTF-16 document is not an XML flow either for this check.
- Order of the checks: `flow.empty`, `flow.format_not_supported`, `flow.not_well_formed`, `flow.dtd_not_allowed`,
  `flow.unexpected_root`, `flow.declaration_mode_mismatch`.

## Amendment 2026-11-22 — the installation ceiling is enforced by the HTTP gateway

- **Where.** `ApiConfiguration::withDeclarationMode(DeclarationMode)` holds the `declaration_mode` ceiling of the
  installation; its default is `DeclarationMode::Test` and `Real` is never a default.
- **Check.** `UrssafHttpGateway::deposit()` calls `DeclarationMode::allows()` with the mode of the flow **first**, before
  the size check, the credentials lookup and any HTTP call, so no token call is made either. A `Real` flow with a
  `Test` ceiling is an `InvariantViolation`, code `declaration_mode.not_allowed` (parameters `mode`,
  `installation_mode`). A `Test` flow is allowed whatever the ceiling.
- This is the lowest layer that can enforce the rule: the application layer ([ADR 0007](0007-declaration-model.md)) may
  refuse earlier, but a gateway built directly with `UrssafGatewayFactory` is guarded as well.

## Amendment 2026-11-22 — the guards as implemented (lot 5)

- `DepositDpaeHandler` checks the installation ceiling (`DeclarationMode::allows()`) **before** recording or claiming
  anything (`declaration_mode.not_allowed`, parameters `mode`, `installation_mode`); the gateway keeps its own check, so
  both entry points are guarded.
- `SimulatedGatewayFactory::create(string $declarationMode, Clock $clock, IdGenerator $ids): SimulatedDpaeGateway` lives
  in `Bridge\Symfony\DependencyInjection` (it imports nothing from Symfony). It **fails closed**: only the exact value `test`
  builds the simulator; `real`, an unresolved `%env()%` placeholder, an empty or differently cased value throw
  `LogicException`. There is no production host to refuse: "production" is `real` (§"The mode").
- `UrssafDpaeBuilder::withGateway()` refuses any gateway when the `ApiConfiguration` ceiling is `real`, whichever gateway it
  is (PAJE / TDAE model); the HTTP gateway built by the builder itself stays allowed.
- `SimulatedDpaeGateway` refuses a `Real` flow with a `LogicException` first of all (before the call is journaled, a scripted
  failure is consumed or the flux is recorded), never produces the returns
  `20` / `41` (it only lists nature `10`), and can script a failure per operation, a rejection notice and a lost answer
  (the flux is then recorded but `OutcomeUnknown` is thrown, which is what the reconciliation looks for).
