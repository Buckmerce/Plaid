# AGENTS.md — PayBridge for Plaid Engineering Policy

## 1. Project identity

- **Plugin:** PayBridge for Plaid — WooCommerce Pay by Bank
- **Slug:** `paybridge-for-plaid`
- **Main file:** `paybridge-for-plaid.php`
- **Text domain:** `paybridge-for-plaid`
- **PHP namespace:** `PayBridge\\Plaid`
- **Gateway ID:** `paybridge_plaid`
- **Prefix:** `pbfp_`
- **Order meta prefix:** `_pbfp_`
- **REST namespace:** `paybridge-for-plaid/v1`
- **Logger source:** `paybridge-for-plaid`
- **Action Scheduler group:** `paybridge-for-plaid`

This repository is financial infrastructure. Correctness, idempotency, security, auditability and recoverability take precedence over development speed or code brevity.

## 2. Mandatory reading before any code change

Every AI agent and human contributor MUST read repository documentation before modifying code.

### Always read first

1. `AGENTS.md`
2. `docs/README.md`
3. `docs/DEVELOPMENT_HANDBOOK.md`
4. `docs/ARCHITECTURE.md`
5. `docs/api/README.md`
6. `docs/api/PLAID_TRANSFER.md`
7. `docs/api/WOOCOMMERCE.md`
8. `docs/SECURITY.md`
9. `docs/PAYMENT_LIFECYCLE.md`
10. `docs/STATE_MACHINE.md`
11. `docs/CONCURRENCY_IDEMPOTENCY.md`
12. `docs/TESTING_QA.md`

### Then read all documents relevant to the task

Examples:

- webhooks → `docs/WEBHOOKS_AND_EVENTS.md`
- database/schema → `docs/DATA_MODEL.md`
- checkout/HPOS/Blocks → `docs/WOO_COMMERCE_INTEGRATION.md`
- release → `docs/CI_CD_RELEASE.md`
- support/diagnostics → `docs/OBSERVABILITY_SUPPORT.md`, `docs/RUNBOOKS.md`
- privacy → `docs/PRIVACY_COMPLIANCE.md`
- architectural change → `docs/adr/README.md` and applicable ADRs

An agent MUST NOT modify provider or WooCommerce integration code without reading the corresponding API documentation in `docs/api/`.

## 3. Documentation authority and freshness

Repository documentation describes the intended PayBridge architecture, but third-party API behavior can change.

Before implementing or changing Plaid, WooCommerce, WordPress or Action Scheduler behavior:

1. read the relevant `docs/api/*` document;
2. follow its official source links;
3. verify current official documentation;
4. compare current external behavior with repository assumptions;
5. update repository API documentation if the external contract changed;
6. only then modify implementation.

Never implement an endpoint, field, status or webhook schema from memory.

If official documentation contradicts an internal document, do not silently choose one. Update the internal document and, when architectural, add or supersede an ADR.

## 4. Mandatory architecture rules

The MVP payment path is:

```text
WooCommerce order
    ↓
server-side Transfer Intent
    ↓
PayBridge payment page
    ↓
server-side Link token bound to Transfer Intent
    ↓
Plaid Transfer UI
    ↓
server-side /transfer/intent/get verification
    ↓
Plaid Transfer events
    ↓
/transfer/event/sync
    ↓
WooCommerce state transition
```

The browser is never authoritative for payment success.

Do not replace this architecture casually. Architectural changes require an ADR.

## 5. Absolute payment invariants

The following must always remain true:

1. Browser state is not authoritative payment state.
2. WooCommerce server data is authoritative for order amount and currency.
3. One intended order payment must not accidentally create multiple Plaid transfers.
4. Every Plaid payment object must be traceable to one WooCommerce order/payment attempt.
5. Every PayBridge-paid order must retain traceable Plaid identifiers.
6. Invalid or unverified webhooks must never mutate payment state.
7. Duplicate webhooks and duplicate transfer events must be harmless.
8. Out-of-order events must not regress state incorrectly.
9. A failed payment must never be represented as successful.
10. An ACH return must never be silently ignored.
11. Production must never invoke Sandbox-only APIs.
12. Secrets must never be exposed to frontend code, customer notices or logs.
13. Financial amounts must not rely on binary floating-point equality.
14. A database/locking failure must fail closed before remote payment creation.

Any change violating one of these invariants is a release blocker.

## 6. Code architecture

Keep WordPress hooks/controllers thin. Domain logic belongs in services.

Current modules (1.0):

```text
src/
├── Bootstrap.php          # requirements check, then Plugin::register()
├── Plugin.php             # hook wiring only
├── Container.php          # service construction (Plaid client created lazily)
├── Settings/              # typed settings, AccountIdentity, AccountScope (ADR-0018), AccountChangeGuard (ADR-0015)
├── Gateway/               # WC_Payment_Gateway (payments + refunds adapter) + availability rules (no Plaid calls)
├── Checkout/              # order-pay page, customer access checks, Blocks integration
├── Plaid/
│   ├── Client/            # the only HTTP boundary to Plaid (endpoint allowlist, Sandbox guard)
│   ├── TransferIntent/
│   ├── Transfer/          # /transfer/get, /transfer/cancel, /transfer/event/sync
│   ├── Refund/            # /transfer/refund/create|get|cancel
│   ├── Link/
│   ├── Webhook/           # verification keys + ES256 verification
│   ├── DTO/
│   └── Exception/
├── Payment/               # snapshot, state machine, attempts + history, completion, events,
│                          # order projection, monitoring policy, return retry policy (ADR-0019),
│                          # manual actions, alerts
├── Refund/                # refund policy, service, state machine, events, monitoring (ADR-0016)
├── Persistence/           # installer (schema 3), payment index/reservations, refund store,
│                          # event store, cursor, DB mutex, payment epoch (all account-scoped, ADR-0018)
├── Background/            # Action Scheduler jobs: event sync, reconciliation
├── Admin/                 # configuration status, connection test, diagnostics, order panel,
│                          # orders-list column, Site Health, notices
├── REST/                  # /link-token, /complete, /webhook controllers
├── CLI/                   # wp paybridge-plaid commands
├── Logging/               # logger + redactor
├── Exception/
└── Support/               # Decimal, Money (cent arithmetic), Requirements, SiteMarker
```

Order-related logic lives in `Payment/`, refund logic in `Refund/` (never in the payment state
machine); security controls live next to the code they protect (`Checkout/PaymentAccess`,
`Plaid/Webhook`, `Logging/Redactor`, `Settings/AccountChangeGuard`); diagnostics live in `Admin/`.
Background maintenance of existing payments must never depend on the gateway's enabled switch
(ADR-0014); it runs while real work exists (ADR-0021). Everything read from a Plaid event stream is
scoped by environment + account (ADR-0018). A returned transfer is never followed by another debit
for the same order (ADR-0019).
Update this tree when modules are added or moved.

Do not create god classes or provider API calls inside `WC_Payment_Gateway`.

## 7. WooCommerce rules

- Classic Checkout is mandatory.
- Checkout Blocks is mandatory.
- HPOS is mandatory.
- Use WooCommerce order CRUD.
- Do not directly query `wp_posts`/`wp_postmeta` for order business logic.
- Do not use APIs explicitly marked internal by WooCommerce.
- Declare compatibility only after tests pass.
- Checkout Blocks must use supported payment method integration APIs, not DOM hacks.

## 8. Plaid rules

- MVP uses Plaid Transfer UI for one-time bank payments.
- Link `onSuccess` alone does not prove funds movement.
- `/transfer/intent/get` is authoritative for transfer-intent capture state.
- Transfer lifecycle must be reconciled from Plaid Transfer events.
- `TRANSFER_EVENTS_UPDATE` must lead to `/transfer/event/sync`.
- Webhooks must be cryptographically verified before mutation.
- Use a maintained JWT/JWK implementation; never hand-roll ES256 verification.

## 9. Security rules

Every state-changing admin action requires capability validation and CSRF protection where applicable.

Every customer-facing order endpoint must validate appropriate order/session ownership; a numeric order ID alone is never authorization.

Every REST route must define explicit method, schema, validation and authorization.

Secrets and sensitive values must be recursively redacted from logs.

Review `docs/SECURITY.md` before any security-sensitive change.

## 10. Idempotency and concurrency

Payment creation must be protected by durable reservation/locking.

The system must tolerate:

- double-click;
- repeated `process_payment()`;
- browser refresh;
- completion callback retry;
- Plaid timeout;
- worker crash;
- webhook replay;
- Action Scheduler retry;
- two concurrent PHP workers.

Read `docs/CONCURRENCY_IDEMPOTENCY.md` before touching payment creation.

## 11. Data ownership

PayBridge is a new independent plugin.

Never read, migrate, rename, edit or delete data belonging to another payment plugin.

PayBridge-owned persistent identifiers must use PayBridge-specific names.

Uninstall must be conservative and must only delete PayBridge-owned data when explicit merchant cleanup is enabled.

## 12. Tests are part of the feature

A payment feature is incomplete until success, failure, retry and duplicate paths are tested.

Required quality layers:

- PHPCS;
- PHPStan;
- PHPUnit;
- WordPress/WooCommerce integration tests;
- JavaScript/TypeScript tests where applicable;
- browser/E2E tests;
- package smoke test;
- real Plaid Sandbox tests when credentials are available.

Never claim a test passed without executing it.

Never weaken a test merely to make CI green.

## 13. Sandbox release gates

Verify current Plaid documentation before relying on simulation amounts. The current documented MVP scenarios include:

- `$11.11` → successful ACH debit lifecycle;
- `$22.22` → failed transfer;
- `$33.33` → successful lifecycle followed by ACH return R01;
- refunds: `$1.11` → pending → posted → settled → returned, `$2.22` → failed; other refunds
  stay pending until `/sandbox/transfer/refund/simulate`.

These flows must be represented in automated or real-Sandbox acceptance testing
(`npm run test:sandbox`, `tests/E2E/sandbox-refunds.php`). The Sandbox gate uses the same Transfer UI
shape as Production: a Link customization with Account Select "Enabled for one account"
(`PAYBRIDGE_PLAID_SANDBOX_LINK_CUSTOMIZATION`, ADR-0020). A `v*` release tag requires this gate.

## 14. Documentation maintenance

Code and documentation are one deliverable. Documentation is version-controlled; CI fails when
mandatory documentation stops being tracked or a relative link breaks (`scripts/check-docs.sh`).
The Plaid documentation copy under `docs/api/plaid-mirror/` is a git-ignored local cache
(`scripts/fetch-plaid-docs.sh`); it never replaces verifying the live official page.

When implementation changes any of these, update docs in the same task:

- payment lifecycle;
- state mapping;
- API contract;
- database schema;
- REST routes;
- Action Scheduler hooks;
- security boundary;
- release procedure;
- compatibility claim.

Architecture changes require an ADR under `docs/adr/`.

## 15. Working procedure for AI agents

Before editing:

1. read mandatory docs;
2. inspect Git status;
3. inspect existing implementation and tests;
4. identify affected payment/security invariants;
5. verify relevant official API docs;
6. state internally which docs/ADRs govern the change.

During editing:

1. preserve working generic infrastructure;
2. keep changes scoped;
3. write or update tests with implementation;
4. use explicit types and domain-specific exceptions;
5. avoid speculative abstractions;
6. never fake external success.

After editing:

1. run focused tests;
2. run relevant static checks;
3. run broader quality gate;
4. verify package if runtime files changed;
5. update documentation;
6. inspect for secret leakage;
7. report exact commands and results.

## 16. Definition of done

A feature is done only when:

```text
implementation exists
+ authoritative success path works
+ failure path works
+ retry path works
+ duplicate/concurrency path is safe
+ security boundary is intact
+ tests exist and pass
+ documentation matches
+ release package contains the implementation
```

Financially unsafe “mostly working” behavior is not acceptable.
