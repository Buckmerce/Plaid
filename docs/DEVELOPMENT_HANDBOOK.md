# PayBridge Development Handbook

**Product:** PayBridge for Plaid — WooCommerce Pay by Bank  
**Audience:** senior WordPress/WooCommerce engineers, fintech backend engineers, QA/security reviewers, AI coding agents  
**Goal:** define how to build and maintain a production-grade bank-payment extension without compromising payment integrity.

---

## 1. Engineering philosophy

PayBridge is not a normal form-processing plugin. It coordinates asynchronous financial side effects across WooCommerce, Plaid, the shopper browser, background workers and webhooks.

The architecture is therefore optimized for:

1. correctness;
2. idempotency;
3. failure recovery;
4. auditability;
5. security;
6. operability;
7. backwards-compatible maintenance.

Optimization for fewer classes or fewer lines of code is secondary.

### Core rule

> A false-positive payment confirmation is materially worse than temporarily leaving an order on hold.

When evidence is incomplete, fail closed and reconcile later.

---

## 2. System responsibilities

### WooCommerce owns

- order identity;
- amount and currency;
- customer/order data;
- order lifecycle;
- stock lifecycle;
- checkout/session semantics;
- administrator order management.

### PayBridge owns

- mapping a WooCommerce payment attempt to Plaid;
- Plaid Transfer Intent orchestration;
- secure Link token creation;
- server-side verification;
- transfer/event correlation;
- payment state mapping;
- background reconciliation;
- safe diagnostics and audit metadata.

### Plaid owns

- bank connectivity;
- Transfer UI authorization experience;
- transfer-intent authorization/capture semantics;
- transfer execution;
- transfer events;
- provider risk decisioning where applicable.

### Browser owns

Only presentation and user interaction. It is **never** the source of truth for payment amount or success.

---

## 3. Architectural style

Use a layered modular architecture:

```text
WordPress / WooCommerce adapters
           ↓
Application services
           ↓
Payment domain
           ↓
Provider abstractions
           ↓
Infrastructure / persistence
```

Dependencies must point inward toward domain/application behavior. Provider-specific DTOs must not leak arbitrarily into checkout/admin code.

### Recommended bounded modules

- `Gateway` — WooCommerce payment gateway adapter.
- `Checkout` — Classic, Blocks, payment page.
- `Payment` — payment attempt, state machine, amount/snapshot rules.
- `Plaid` — all Plaid API specifics.
- `Persistence` — locks, events, schema.
- `Background` — Action Scheduler jobs/reconciliation.
- `Order` — WooCommerce order projection and admin UI.
- `Security` — webhook validation, secret boundaries, request authorization.
- `Diagnostics` — Site Health/support tooling.
- `Logging` — structured safe logs/redaction.

---

## 4. Payment-authority hierarchy

When signals conflict, use this order of authority:

1. **authoritative Plaid API response fetched server-side**;
2. **cryptographically verified Plaid event fetched through event-sync**;
3. persisted PayBridge state derived from prior authoritative provider data;
4. WooCommerce local workflow state;
5. browser callbacks/redirect parameters.

The browser can trigger verification, but cannot prove success.

---

## 5. MVP transaction model

MVP uses Plaid Transfer UI.

```text
process_payment()
   ↓
validate order + configuration
   ↓
acquire payment-attempt reservation
   ↓
create/reuse Transfer Intent
   ↓
persist transfer_intent_id
   ↓
redirect to PayBridge payment page
   ↓
create Link token server-side
   ↓
Transfer UI
   ↓
Link onSuccess
   ↓
server /transfer/intent/get
   ↓
persist transfer_id/state
   ↓
on-hold / async state
   ↓
verified webhook notification
   ↓
/transfer/event/sync
   ↓
state machine
   ↓
WooCommerce projection
   ↓
monitoring until the Plaid return windows close (ADR-0014)
```

Refunds follow their own idempotent path and state machine
(`process_refund()` → reservation → `/transfer/refund/create` → refund events), never the
payment state machine (ADR-0016). Disabling the gateway stops new payments only; existing
payments, returns and refunds are always maintained while credentials exist. A returned payment
is never debited again for the same order (ADR-0019).

The complete design is in `PAYMENT_LIFECYCLE.md`.

---

## 6. Money handling

Money must be represented as decimal strings or a dedicated money/value object.

Never use binary floating point to decide whether provider and WooCommerce values match.

A payment attempt stores an immutable snapshot:

- order ID;
- normalized amount;
- currency;
- environment;
- gateway ID;
- attempt identity;
- timestamp.

Any remote object that does not match the expected snapshot goes to `manual_review` / safe failure rather than being accepted.

---

## 7. Idempotency before remote side effects

Every remote money-movement creation path follows:

```text
validate
→ reserve locally
→ re-read state under reservation
→ create/reuse remote object
→ persist remote identifier
→ release/advance reservation
```

Never:

```text
call provider
→ later discover another worker did the same thing
```

If a provider call times out after the remote side effect may have happened, the state becomes **ambiguous**, not automatically retryable. Resolve by querying Plaid/reconciliation before creating another payment.

See `CONCURRENCY_IDEMPOTENCY.md`.

---

## 8. Asynchronous state design

ACH is not card authorization. A transfer can progress through multiple states and can later return.

PayBridge therefore separates:

- transfer creation/capture;
- operational fulfillment policy;
- final provider lifecycle.

No single browser event is final settlement evidence.

Use explicit state transitions documented in `STATE_MACHINE.md`.

---

## 9. Webhook design

Webhooks are an availability optimization, not the sole source of truth.

Requirements:

- read raw request body;
- verify `Plaid-Verification` JWT;
- require ES256;
- fetch/cache Plaid JWK by `kid`;
- verify signature;
- validate freshness/claims;
- compare request body SHA-256 in constant time;
- enqueue processing;
- return quickly.

For `TRANSFER_EVENTS_UPDATE`, fetch authoritative events with `/transfer/event/sync`.

Background reconciliation must recover from webhook loss.

---

## 10. Database strategy

Use WooCommerce CRUD for order state/meta.

Custom tables are justified only for capabilities that require stronger database semantics than order meta can provide, such as:

- durable concurrency leases;
- unique provider event identities;
- event processing leases/retries.

Custom schema must have:

- deterministic installer;
- versioned schema;
- runtime verification;
- required unique indexes;
- explicit ownership;
- upgrade tests.

See `DATA_MODEL.md`.

---

## 11. Background processing

Use Action Scheduler for:

- transfer-event sync;
- stale-payment reconciliation;
- bounded retries;
- asynchronous operational work.

Rules:

- callbacks are idempotent;
- arguments are small and non-sensitive;
- retries distinguish transient from permanent failures;
- jobs are grouped under `paybridge-for-plaid`;
- scheduling is done after Action Scheduler initialization;
- no infinite retry loops (event rows: exponential backoff, abandoned after 20 attempts;
  event-sync follow-ups: 1 → 15 min backoff; reconciliation: bounded per run);
- background maintenance never depends on the gateway's enabled switch (ADR-0014) and runs while
  real work exists — monitored payments, open refunds, stored events, a failed sync (ADR-0021);
- every job that reads a Plaid event stream is scoped by environment + Plaid account (ADR-0018).

---

## 12. Security model

The key trust boundaries are:

- shopper browser ↔ WordPress;
- Plaid ↔ webhook endpoint;
- wp-admin ↔ payment operations;
- WordPress ↔ Plaid API;
- logs/support exports ↔ operators.

Mandatory principles:

- least privilege;
- deny by default;
- customer order ownership validation;
- explicit admin capabilities;
- nonces for browser-originated mutations;
- cryptographic webhook verification;
- no secrets in browser/logs;
- output escaping;
- strict input schemas;
- SSRF-safe fixed provider hosts.

See `SECURITY.md`.

---

## 13. WooCommerce compatibility

The plugin must support:

- Classic Checkout;
- Cart/Checkout Blocks payment registration;
- HPOS;
- guest checkout;
- logged-in checkout;
- order-pay/retry flows.

Payment logic must not be duplicated between Classic and Blocks.

WooCommerce adapters should invoke the same application services.

---

## 14. Observability

Every payment should be diagnosable using safe identifiers without database spelunking.

Operators should be able to answer:

- what order is this?
- what environment?
- what Transfer Intent?
- what transfer?
- what is the provider status?
- what was the last provider event?
- when was it last synchronized?
- is reconciliation healthy?
- why did it fail/return?

Logs must be structured and redacted.

---

## 15. Test strategy

Payment tests are organized around failure modes, not only classes.

Minimum matrix:

- success;
- provider decline/failure;
- return;
- browser retry;
- double-click;
- parallel requests;
- API timeout;
- local DB error;
- webhook replay;
- invalid webhook;
- event replay;
- out-of-order event;
- worker retry;
- stale payment reconciliation;
- HPOS;
- Blocks;
- Classic Checkout.

See `TESTING_QA.md`.

---

## 16. Release quality

A release is built from clean source and validated as an installable ZIP.

Required gates include:

- Composer validation;
- PHP syntax;
- PHPCS;
- PHPStan;
- PHPUnit;
- frontend build/lint;
- integration tests;
- E2E;
- Plugin Check;
- package contents validation;
- fresh ZIP activation smoke test.

Do not ship a stale prebuilt ZIP.

---

## 17. Documentation-driven development

Documentation is executable design intent.

Before a task:

1. read the handbook;
2. read API map;
3. read applicable subsystem docs;
4. read relevant ADRs;
5. verify external docs.

After a task:

1. update design docs if behavior changed;
2. add an ADR if architecture changed;
3. update API map if external assumptions changed;
4. update tests and runbooks.

---

## 18. ADR policy

Write an ADR when changing any of these:

- payment authorization architecture;
- authoritative source-of-truth rule;
- state machine semantics;
- persistence model;
- concurrency strategy;
- webhook verification approach;
- third-party dependency with security implications;
- WooCommerce compatibility architecture.

Do not rewrite historical ADRs to hide past decisions. Supersede them.

---

## 19. Review checklist

Every payment-related pull request should answer:

- Could this create money twice?
- Could the browser fake success?
- Could an old event overwrite newer state?
- What happens on timeout after remote side effect?
- What happens if database persistence fails?
- Is the amount authoritative and exact?
- Is the environment bound to the attempt?
- Is everything read from a Plaid event stream scoped to that Plaid account?
- Could this re-debit a returned payment?
- Can a guest access another order?
- Can invalid webhook input reach business logic?
- Are logs safe?
- Does HPOS still work?
- Do Blocks and Classic use the same domain logic?
- Is there a recovery/reconciliation path?

If any answer is unclear, the change is not ready.
