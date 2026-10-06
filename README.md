# Bank Payments via Plaid for WooCommerce

[![Quality](https://github.com/Buckmerce/Plaid/actions/workflows/quality.yml/badge.svg?branch=master)](https://github.com/Buckmerce/Plaid/actions/workflows/quality.yml)
![PHP](https://img.shields.io/badge/PHP-8.1%E2%80%938.4-777BB4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-21759B?logo=wordpress&logoColor=white)
![WooCommerce](https://img.shields.io/badge/WooCommerce-8.7%2B-96588A?logo=woocommerce&logoColor=white)
![Plaid](https://img.shields.io/badge/Plaid-Transfer-000000)
![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)

**Buckmerce** is a WooCommerce payment gateway for one-time US bank payments through **Plaid Transfer UI**.

The customer chooses **Pay by Bank**, connects a bank through Plaid Link, selects an account and authorizes an ACH debit. Buckmerce keeps the server authoritative, follows the payment through the Plaid Transfer lifecycle, reconciles missed events, projects the result into WooCommerce, supports native refunds, and continues monitoring for ACH returns after the order is paid.

This repository contains the complete human-readable PHP, TypeScript and SCSS source plus the build and test tooling used to produce the WordPress.org release ZIP.

> Buckmerce is an independent third-party integration. It is not developed, endorsed or supported by Plaid Inc., WooCommerce or Automattic.

---

## Highlights

- **Plaid Transfer UI** for one-time Pay by Bank checkout.
- **Standard ACH** and **Same Day ACH**.
- **WooCommerce Classic Checkout** and **Checkout Blocks**.
- **HPOS compatible** using WooCommerce CRUD APIs.
- **Server-authoritative payment confirmation**: browser callbacks never mark an order paid.
- **Verified Plaid webhooks** using ES256 JWT verification and exact request-body hashing.
- **Durable transfer event ingestion** with account-scoped cursors and idempotent event processing.
- **Background reconciliation** for missed webhooks, stale payments and open refunds.
- **Native WooCommerce refunds** with full and multiple partial refunds.
- **ACH return handling** with order state projection, notes, alerts and merchant email.
- **Duplicate-payment protection** using durable reservations and database mutexes.
- **Payment attempt history** for failed attempts without confusing late events with a newer attempt.
- **Plaid account-change guard** while Production payments or refunds are still being monitored.
- **Configuration health**, connection testing, diagnostics, Site Health and structured redacted logs.
- **WP-CLI operations** for status, event sync, reconciliation, order sync and Sandbox testing.
- **Reproducible release packaging** with a strict runtime allowlist.

---

## Requirements

| Component | Requirement |
| --- | --- |
| WordPress | 6.6+ |
| WooCommerce | 8.7+ |
| PHP | 8.1–8.4 |
| PHP extension | OpenSSL |
| Database | MySQL 8.0+ or MariaDB 10.11+ |
| Currency | USD |
| Plaid | Plaid Transfer enabled |
| Production | HTTPS |

The plugin declares compatibility with WooCommerce **custom order tables (HPOS)** and **cart/checkout blocks**.

---

## Quick start

### 1. Install

Install the release ZIP as a normal WordPress plugin and activate it with WooCommerce active.

Open:

```text
WooCommerce → Settings → Payments → Buckmerce for Plaid
```

### 2. Configure Plaid credentials

In the Plaid Dashboard open:

```text
Developers → Keys
```

Start with Sandbox and enter:

- Client ID
- Sandbox Secret

Buckmerce never renders the stored Secret back into the settings field. Saving an empty Secret preserves the existing value; removing it is an explicit action.

### 3. Create the Link customization

In the same Plaid environment create and publish a Link customization with:

```text
Account Select = Enabled for one account
```

Use the same language as the WooCommerce store and enter the customization name in Buckmerce.

The Link customization is required in both Sandbox and Production.

### 4. Configure the funding source

For the normal Plaid Ledger configuration, leave **Funding Account ID** empty.

Only Plaid Transfer accounts without Ledger should provide a Funding Account ID.

### 5. Configure the webhook

Buckmerce displays the site-specific endpoint:

```text
https://example.com/wp-json/buckmerce-plaid/v1/webhook
```

Add it in the Plaid Dashboard as a **Transfer event** webhook for the selected environment.

### 6. Test and enable

Use **Test connection**. The settings screen evaluates the local configuration and records the Plaid connectivity result.

When the status is ready, enable:

```text
Offer Pay by Bank at checkout
```

Test the complete flow in Sandbox before switching to Production.

---

## Checkout architecture

```text
Customer
   │
   │ chooses Pay by Bank
   ▼
WooCommerce
   │
   │ process_payment()
   ▼
Buckmerce payment reservation
   │
   │ /transfer/intent/create
   ▼
Plaid Transfer Intent
   │
   │ /link/token/create
   ▼
Plaid Link / Transfer UI
   │
   │ customer connects bank and authorizes
   ▼
Buckmerce /complete
   │
   │ /transfer/intent/get
   │ /transfer/get
   ▼
WooCommerce order → On hold
   │
   │ verified webhook + /transfer/event/sync
   ▼
pending → posted → settled → funds_available
   │
   └─ failed / cancelled / returned
```

The browser is intentionally not authoritative. A successful Plaid Link callback means the UI flow completed; it does **not** mean the WooCommerce order is paid.

Buckmerce re-reads the Transfer Intent and Transfer from Plaid and verifies the server-side snapshot before projecting state into WooCommerce.

---

## Payment creation

For each new payment attempt Buckmerce creates an immutable server-side snapshot containing the authoritative order data, including:

- WooCommerce order ID
- amount
- currency
- Plaid environment
- attempt identity

The Transfer Intent request uses:

```text
mode = PAYMENT
iso_currency_code = USD
ach_class = web
network = same-day-ach | ach
```

The customer legal name is derived from WooCommerce billing first and last name and is required. A valid billing email is included when available.

Application metadata is limited to non-secret correlation values.

### Duplicate-payment protection

Payment creation is protected by:

- a plugin-owned payment reservation table;
- a database advisory mutex;
- immutable attempt snapshots;
- compare-and-set transfer binding;
- idempotent completion behavior.

Parallel tabs, repeated completion requests and double clicks should not create multiple active transfers for one order.

---

## WooCommerce integration

### Classic Checkout

The gateway participates as a normal `WC_Payment_Gateway`.

Before payment begins, Buckmerce validates that the billing first and last name can represent the bank account holder's legal name.

### Checkout Blocks

Buckmerce registers a WooCommerce Blocks payment method and uses the same server-side gateway implementation and order lifecycle.

### HPOS

The plugin declares HPOS compatibility and uses WooCommerce CRUD APIs for order state and order metadata. It does not depend on direct `wp_posts` / `wp_postmeta` access for payment state.

### Order payment page

After checkout, the customer is sent to WooCommerce's native order-pay flow. The page displays server-derived order information and loads Plaid Link only when the order can be paid.

Customer payment REST calls are protected by:

- order ID;
- WooCommerce order key;
- ownership/session access;
- a payment nonce.

A numeric order ID alone is never sufficient.

---

## Gateway configuration

| Setting | Purpose |
| --- | --- |
| Offer Pay by Bank at checkout | Enables new payments. Existing payments/refunds continue to be maintained when disabled. |
| Title | Checkout payment-method title. |
| Description | Checkout payment-method description. |
| Environment | Sandbox or Production. |
| Client ID | Plaid account Client ID. |
| Secret | Environment-specific Plaid Secret. |
| Funding Account ID | Optional for Plaid Transfer accounts without Ledger. |
| Link customization name | Required published Transfer UI customization. |
| Bank statement description | Stable statement descriptor, maximum 10 letters/digits/spaces. |
| Payment network | Same Day ACH or Standard ACH. |
| Mark order paid when | Funds available (default) or settled. |
| Debug logging | Enables debug/info entries in WooCommerce logs. |
| Uninstall cleanup | Removes plugin settings/tables when the plugin is deleted; order audit data remains. |

Production payments are unavailable unless the site uses HTTPS. New payments are also unavailable when the order currency is not USD.

---

## Payment lifecycle

Internal payment states include:

```text
new
intent_creating
intent_created
intent_pending
intent_failed
intent_uncertain
transfer_created
pending
posted
settled
funds_available
failed
cancelled
returned
manual_review
```

Transitions are explicitly validated. Unknown, stale or conflicting events are not allowed to silently regress payment state.

### WooCommerce projection

Typical successful flow:

```text
Pending payment
  → On hold
  → payment_complete()
  → Processing or Completed
```

The final Processing/Completed choice remains WooCommerce's normal `payment_complete()` behavior.

The merchant can choose whether `payment_complete()` occurs when Plaid reports:

- **Funds available** — default and recommended by the plugin; or
- **Settled**.

---

## ACH returns

Buckmerce continues monitoring completed bank payments through the applicable return-monitoring period.

When a payment is returned, the plugin records the return information and surfaces it operationally:

- payment state becomes `returned`;
- WooCommerce order is moved to Failed when appropriate;
- private order note is added;
- orders-list status indicator is updated;
- persistent merchant alert is created;
- merchant email is sent;
- the original payment and attempt history remain available.

A returned payment is **not automatically debited again** through Transfer UI.

Plaid has specific retry semantics for certain ACH return codes when using direct transfer creation. The 1.0 Transfer UI flow does not express those retry markers, so Buckmerce refuses a new bank debit for the returned order instead of risking an invalid reprocessing attempt.

Payments that failed before money moved can start a fresh payment attempt.

---

## Refunds

Buckmerce implements WooCommerce automatic refunds through Plaid Transfer.

Supported behavior includes:

- full refunds;
- multiple partial refunds;
- deterministic idempotency keys;
- refund reservations;
- refund lifecycle tracking;
- reconciliation of uncertain refund creation;
- refund cancellation when Plaid still allows it;
- merchant alerts for failed or returned refunds;
- detection of refunds created outside WooCommerce.

The plugin deliberately applies a stricter merchant policy than Plaid's minimum API eligibility: it waits for the debit to reach an accepted settled state before offering a normal WooCommerce refund path.

Refund state is maintained independently from the order payment state.

Typical refund lifecycle:

```text
creating
  → pending
  → posted
  → settled

or

creating / pending / posted
  → failed
  → cancelled
  → returned
  → uncertain
```

---

## Webhooks

Endpoint:

```text
POST /wp-json/buckmerce-plaid/v1/webhook
```

The endpoint is reachable without a WordPress login because Plaid is the caller, but it is not unauthenticated.

Authorization runs in the REST `permission_callback` and verifies the Plaid request before business processing.

Verification includes:

1. request body size limit;
2. `Plaid-Verification` JWT presence and shape;
3. `alg = ES256`;
4. validated `kid`;
5. Plaid verification key fetched through `/webhook_verification_key/get`;
6. ES256 signature verification using the bundled, namespace-prefixed `firebase/php-jwt`;
7. issued-at freshness;
8. SHA-256 of the exact raw request body;
9. constant-time comparison with `request_body_sha256`.

Verified payloads are request-scoped and consumed once by the webhook handler.

A valid `TRANSFER_EVENTS_UPDATE` webhook does not directly modify an order. It schedules transfer-event synchronization; authoritative state comes from Plaid's event stream and transfer reads.

---

## REST endpoints

Namespace:

```text
buckmerce-plaid/v1
```

| Route | Method | Purpose | Authorization |
| --- | --- | --- | --- |
| `/link-token` | POST | Issue a Plaid Link token for the current payment attempt | Order access + payment nonce |
| `/complete` | POST | Re-read the Transfer Intent after Link finishes | Order access + payment nonce |
| `/webhook` | POST | Receive Plaid Transfer event notifications | Plaid JWT + request body verification |

The customer-facing REST endpoints also have bounded per-order rate limiting.

---

## Plaid API surface

The runtime client uses an explicit endpoint allowlist.

### Payment and Link

```text
/transfer/intent/create
/transfer/intent/get
/link/token/create
/transfer/get
/transfer/cancel
```

### Event synchronization

```text
/transfer/event/sync
```

### Refunds

```text
/transfer/refund/create
/transfer/refund/get
/transfer/refund/cancel
```

### Configuration and diagnostics

```text
/transfer/configuration/get
/transfer/ledger/get
/webhook_verification_key/get
```

### Sandbox-only operations

```text
/sandbox/transfer/simulate
/sandbox/transfer/refund/simulate
/sandbox/transfer/fire_webhook
```

The Plaid client refuses Sandbox-only endpoints in Production.

All Plaid API requests use JSON over HTTPS, certificate verification, a 30-second timeout, no redirects and the pinned Plaid API version:

```text
2020-09-14
```

Plaid `request_id` values are retained for support correlation.

---

## Background processing

Buckmerce uses WooCommerce **Action Scheduler**.

Action group:

```text
buckmerce-plaid
```

Primary hooks:

```text
buckmerce_plaid_transfer_event_sync
buckmerce_plaid_reconcile
buckmerce_plaid_reconcile_continue
```

### Event sync

Verified webhooks enqueue a unique event-sync action. Duplicate notifications coalesce when an event-sync job is already scheduled.

The event sync reads Plaid `/transfer/event/sync` with an account-scoped cursor and stores the events before applying them.

### Reconciliation

A recurring reconciliation pass runs every 15 minutes while there is actual operational work:

- monitored payments;
- open refunds;
- event backlog; or
- a failed event sync that still needs retrying.

A pass can also schedule a bounded continuation one minute later when more work remains.

Disabling the payment gateway does not abandon existing payment maintenance. Once no payment/refund/event work remains, recurring reconciliation can stop.

---

## Persistence model

Buckmerce owns three operational tables:

```text
{prefix}buckmerce_plaid_events
{prefix}buckmerce_plaid_payment_locks
{prefix}buckmerce_plaid_refunds
```

### Events

Stores durable Plaid transfer/refund events with:

- environment;
- Plaid account fingerprint;
- event ID;
- event type;
- transfer/order correlation;
- processing state;
- lease/attempt metadata.

Event identity is unique per environment + Plaid account + event ID.

### Payment locks

One row per WooCommerce order coordinates:

- current attempt;
- Transfer Intent ID;
- Transfer ID;
- payment state;
- account identity;
- reconciliation scheduling;
- monitor-until date;
- reservation ownership.

### Refunds

Tracks:

- WooCommerce refund;
- Plaid refund;
- amount/currency;
- environment/account;
- payment attempt;
- idempotency key;
- refund state;
- reconciliation metadata;
- failure and request IDs.

Order-level payment details remain in WooCommerce order metadata via WooCommerce CRUD.

Schema installation is forward-only and idempotent and verifies required columns/indexes before recording the schema version.

---

## Plaid account isolation

A Plaid Client ID/environment combination is part of the operational identity.

Event streams, cursors, payment epochs and refund identities are scoped so an event from one Plaid account cannot be mistaken for an event with the same numeric ID from another account.

Buckmerce also refuses a dangerous Plaid account/environment switch while monitored Production work still depends on the old credentials.

This protects long-running ACH return and refund monitoring from accidental credential replacement.

---

## Security model

The plugin is designed around fail-closed payment processing.

### Server authority

The browser cannot choose or override:

- order amount;
- currency;
- environment;
- Transfer Intent;
- funding account;
- payment state.

### Secrets

Plaid credentials:

- stay server-side;
- are not returned in settings HTML;
- are not sent to browser JavaScript;
- are recursively redacted from structured logs.

### Logging redaction

Sensitive key fragments include credentials, tokens, cookies, Plaid verification headers, bank account/routing fields, names, email, phone, postal/address data, JWT/signature fields and private/API keys.

Request/response bodies are not used as routine Plaid log payloads.

### Webhook security

Webhook verification uses a maintained JWT library rather than custom cryptography.

### Admin operations

Sensitive merchant actions require:

- `manage_woocommerce`;
- an action-specific nonce;
- server-side revalidation.

### Concurrency

MySQL advisory locks and durable reservations protect creation and mutation paths that cannot safely race.

---

## Observability

### Configuration status

The payment settings screen reports states such as:

```text
Ready
Disabled
Incomplete
Attention required
```

The checklist covers:

- Plaid credentials;
- environment;
- HTTPS;
- USD currency;
- Link customization;
- schema;
- webhook route;
- connection result;
- Action Scheduler/background processing.

### Order panel

The WooCommerce order panel can surface:

- current payment attempt;
- Transfer Intent and status;
- Transfer ID and status;
- created/settled/funds-available dates;
- expected funds date;
- return windows;
- returned/failure/manual-review information;
- last sync/event/request ID;
- refund history;
- previous payment attempts;
- payment/refund alerts.

Available merchant actions are exposed only when appropriate, including:

- Sync with Plaid;
- Cancel bank payment;
- manual-review decisions;
- allow another attempt after eligible failures.

### Diagnostics and Site Health

Diagnostics provide a sanitized support view of:

- versions;
- HPOS/Blocks state;
- database server;
- configuration readiness;
- event cursor;
- background jobs;
- last webhook;
- last event sync;
- last reconciliation;
- payment/refund counts and backlog.

Site Health reports critical/recommended problems for broken configuration or stalled maintenance.

### Logs

WooCommerce log source:

```text
buckmerce-plaid
```

Warnings/errors are available for operational failures; debug/info entries are controlled by the Debug logging setting.

---

## WP-CLI

Buckmerce registers:

```bash
wp buckmerce-plaid status
wp buckmerce-plaid test-connection
wp buckmerce-plaid sync-events
wp buckmerce-plaid reconcile
wp buckmerce-plaid sync-order <order-id>
wp buckmerce-plaid refunds <order-id>
```

Sandbox-only tools:

```bash
wp buckmerce-plaid simulate-refund <refund-id> <refund.posted|refund.settled|refund.failed|refund.returned>

wp buckmerce-plaid fire-sandbox-webhook \
  --webhook-url=https://public.example/wp-json/buckmerce-plaid/v1/webhook
```

CLI output is designed not to expose Plaid secrets.

---

## Extension hooks

Useful emitted actions include:

```text
buckmerce_plaid_transfer_intent_created
buckmerce_plaid_payment_state_changed
buckmerce_plaid_payment_confirmed
buckmerce_plaid_payment_failed
buckmerce_plaid_payment_cancelled
buckmerce_plaid_payment_manual_review
buckmerce_plaid_payment_returned
buckmerce_plaid_webhook_processed
buckmerce_plaid_refund_created
buckmerce_plaid_refund_failed
buckmerce_plaid_refund_returned
buckmerce_plaid_refund_cancelled
```

Merchant alert email recipient can be changed with:

```text
buckmerce_plaid_alert_recipient
```

---

## Development

### PHP dependencies

Install the development toolchain:

```bash
composer install
```

Runtime third-party dependency:

```text
firebase/php-jwt
```

It is namespace-prefixed into:

```text
Buckmerce\Plaid\Vendor\
```

using Strauss to reduce dependency collisions with other WordPress plugins.

### Front-end dependencies

```bash
npm ci
```

Human-readable front-end sources:

```text
resources/ts/
resources/scss/
resources/images/
```

Generated release assets (build output: ignored by Git, shipped only in the release ZIP, no subdirectories):

```text
assets/*.css
assets/*.js
assets/*.svg
```

Build:

```bash
npm run build
```

Typecheck:

```bash
npm run typecheck
```

JavaScript build/test gate:

```bash
npm test
```

### PHP quality gates

```bash
composer validate --strict
composer syntax
composer lint
composer stan
composer test
```

The repository includes unit, integration, browser/E2E, package, Plugin Check and real Plaid Sandbox test paths.

---

## Release package

Build the WordPress release ZIP:

```bash
npm run plugin-zip
```

The packaging script starts from an empty staging directory and copies only explicitly allowed runtime files.

The final ZIP is checked for:

- one expected plugin root;
- required runtime files;
- allowlisted contents only;
- no development/test files;
- no local environment files;
- no source maps;
- no nested archives;
- no obvious credentials;
- source/package parity;
- fresh generated-asset parity;
- PHP syntax;
- identity/version consistency.

Release archives use normalized permissions, timestamps and entry ordering to support deterministic rebuilds.

---

## Testing

The repository includes:

- PHPUnit unit tests;
- WordPress/WooCommerce integration scripts;
- MySQL and MariaDB integration paths;
- concurrency/idempotency tests;
- JavaScript unit tests;
- browser checkout smoke tests;
- Classic Checkout and Checkout Blocks flows;
- HPOS coverage;
- refund lifecycle coverage;
- webhook REST/JWT verification coverage;
- Sandbox transfer/refund simulation;
- real signed Plaid Sandbox webhook testing when credentials are available;
- WordPress Plugin Check against the built ZIP.

GitHub Actions runs the quality matrix for pushes and pull requests and builds the release artifact from the tested commit.

Every tool and platform version in that pipeline is pinned, so a red run means the commit itself is broken. Two checks depend on the day of the run instead: known security advisories (`bash scripts/audit-dependencies.sh`) and Plugin Check comparing `Tested up to` with the current WordPress release. They warn on pushes and pull requests and block a release. A scheduled workflow audits the locked dependencies of `master` every day, and Dependabot opens security-update pull requests.

---

## Privacy and external services

Buckmerce uses Plaid to perform bank-payment functionality.

Server-to-server data sent to Plaid can include:

- Plaid Client ID and Secret;
- order amount and currency;
- statement description;
- customer billing legal name;
- customer billing email;
- internal order/payment correlation metadata;
- refund amount;
- transfer/refund identifiers.

Plaid Link is loaded from:

```text
https://cdn.plaid.com/link/v2/stable/link-initialize.js
```

Plaid API environments:

```text
https://sandbox.plaid.com
https://production.plaid.com
```

Plaid Terms: https://plaid.com/legal/

Plaid Privacy Policy: https://plaid.com/legal/#end-user-privacy-policy

Buckmerce itself does not require a license key, subscription, paid feature unlock, trial timer or usage quota.

---

## Current scope

Version 1.0 intentionally focuses on:

```text
US consumer Pay by Bank
USD
one-time payments
Plaid Transfer UI
ACH debit
WooCommerce
```

Not included in 1.0:

- credit/debit card processing;
- WooCommerce Subscriptions recurring payments;
- saved bank accounts;
- non-USD payment rails;
- automatic re-debit of returned ACH payments.

Keeping these boundaries explicit reduces ambiguity in payment semantics and keeps the one-time Transfer UI flow predictable.

---

## License

Buckmerce is licensed under **GPL-2.0-or-later**.

The bundled `firebase/php-jwt` library is distributed under **BSD-3-Clause**.

Plaid, WooCommerce and WordPress trademarks belong to their respective owners.
