# PayBridge for Plaid — WooCommerce Pay by Bank

WooCommerce payment gateway for US bank (ACH) payments through **Plaid Transfer UI**.
The customer authorizes a debit in Plaid Link; the store marks the order paid only after
Plaid itself reports the transfer as settled / funds available.

| | |
|---|---|
| Plugin slug | `paybridge-for-plaid` |
| Gateway ID | `paybridge_plaid` |
| PHP namespace | `PayBridge\Plaid` |
| Version | 0.1.0 |
| Requires | WordPress 6.6+, WooCommerce 8.5+, PHP 8.1+ (tested up to WordPress 7.1 / WooCommerce 11.1) |
| License | GPL-2.0-or-later |

PayBridge is an independent project. It is not affiliated with, endorsed or sponsored by
Plaid Inc. or Automattic.

---

## Contents

1. [How it works](#how-it-works)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Payment lifecycle and order statuses](#payment-lifecycle-and-order-statuses)
6. [Webhooks, event sync and reconciliation](#webhooks-event-sync-and-reconciliation)
7. [WooCommerce integration (HPOS, Blocks, Classic)](#woocommerce-integration)
8. [Operations: WP-CLI, diagnostics, Site Health](#operations)
9. [Security model](#security-model)
10. [Privacy and external services](#privacy-and-external-services)
11. [Development](#development)
12. [Testing](#testing)
13. [CI and releases](#ci-and-releases)
14. [Project layout](#project-layout)
15. [Documentation](#documentation)

---

## How it works

```
Customer            WooCommerce (PayBridge)                          Plaid
   │  Place order  ──►  order "pending", redirect to order-pay page
   │  Pay by bank  ──►  POST /link-token ─► /transfer/intent/create ─►  intent (PENDING)
   │                                        /link/token/create     ─►  link_token
   │  ◄── Plaid Link (Transfer UI) opens with the link_token
   │  authorizes   ──►  POST /complete (order id + key only)
   │                    server reads /transfer/intent/get          ─►  SUCCEEDED + transfer_id
   │                    order → "on-hold" (awaiting ACH settlement)
   │                                                                   ACH moves money
   │                    ◄── signed webhook TRANSFER_EVENTS_UPDATE (ES256 JWT)
   │                    /transfer/event/sync (cursor)             ─►  pending/posted/settled/
   │                                                                   funds_available/failed/returned
   │                    order → payment_complete() at funds_available (default)
```

Key design points (details in [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) and the ADRs):

- **Server-authoritative.** Browser callbacks never mark an order paid. The amount, currency,
  intent and transfer are always re-read from Plaid and compared with an immutable payment
  snapshot of the order.
- **One intent per order.** A database reservation (`{prefix}paybridge_plaid_payment_locks`)
  plus a MySQL advisory lock guarantee at most one active Transfer Intent per order, even
  with double clicks, parallel tabs or retries.
- **Webhooks are notifications only.** A verified webhook triggers `/transfer/event/sync`;
  order state changes come exclusively from events read from Plaid.
- **Idempotent event processing.** Events are stored by `(environment, event_id)` in
  `{prefix}paybridge_plaid_events` and processed exactly once under a lease.
- **Explicit state machine.** Every transition is validated; stale or conflicting events are
  recorded and never regress an order.
- **Reconciliation.** A background job re-reads open intents and transfers, so a missed
  webhook never leaves an order stuck.

## Requirements

- WordPress 6.6 or later, WooCommerce 8.5 or later, PHP 8.1 or later, MySQL/MariaDB.
- Store currency **USD** (Plaid Transfer debits US bank accounts).
- HTTPS for Production (the gateway is unavailable in Production over plain HTTP).
- A Plaid account with **Transfer** enabled and API keys for the selected environment.
- WP-Cron or a real cron triggering Action Scheduler (WooCommerce's job queue).

## Installation

1. Download `paybridge-for-plaid-<version>.zip` from the GitHub release (or build it, see
   [Development](#development)).
2. WordPress admin → **Plugins → Add New → Upload Plugin**, upload the ZIP and activate.
   Activation creates the two PayBridge tables and verifies their schema; if verification
   fails the gateway stays disabled and an admin notice explains why.
3. Configure the gateway in **WooCommerce → Settings → Payments → PayBridge for Plaid**.

## Configuration

| Setting | Default | Notes |
|---|---|---|
| Enable/Disable | off | Gateway is offered only when fully configured (credentials, USD, HTTPS in Production). |
| Title / Description | "Pay by Bank" | Shown at checkout. |
| Environment | Sandbox | `sandbox` or `production`. Production requires HTTPS. |
| Client ID / Secret | — | From Plaid Dashboard → Developers → Keys. The secret is write-only: it is never rendered back, blank keeps the stored value, **Remove stored secret** clears it. |
| Funding Account ID | empty | Leave empty when the Plaid account uses **Plaid Ledger** (the default; Plaid rejects `funding_account_id` then). See [ADR-0007](docs/adr/0007-ambiguous-intent-and-ledger.md). |
| Link customization name | empty | Optional Link customization, ideally with Account Select "Enabled for one account". |
| Payment network | Same Day ACH | `same-day-ach` or `ach`. |
| ACH class | WEB | `web` (recommended for online consumer payments), `ppd`, `ccd`, `tel`. |
| Mark order paid when | Funds available | `funds_available` (recommended) or `settled`. |
| Reconciliation | on | Background re-check of open payments. Keep enabled. |
| Debug logging | off | Redacted logs in WooCommerce → Status → Logs (source `paybridge-for-plaid`). |
| Uninstall cleanup | off | When on, deleting the plugin removes PayBridge settings, event history and tables. Order payment metadata is always kept. |

**Test connection** on the settings screen (or `wp paybridge-plaid test-connection`) calls Plaid
with the saved keys and reports whether the account is ready for Transfer and whether Ledger
is enabled.

### Plaid Dashboard

- Enable **Transfer** for the environment you use.
- Register the webhook in Plaid Dashboard → **Team Settings → Webhooks → New Webhook**, event type
  **Transfer event**, URL `https://<your-store>/wp-json/paybridge-for-plaid/v1/webhook`
  (shown with a copy button on the settings screen). Plaid allows one Transfer webhook URL
  per environment.
- Without a registered webhook (for example before Production access is granted) payments
  still complete: the 15-minute reconciliation job pulls `/transfer/event/sync` itself, only
  with more latency.

### Sandbox testing

Plaid Sandbox simulates Transfer UI outcomes by amount: **$11.11** succeeds (funds available),
**$22.22** fails, **$33.33** is returned with R01, **$44.44** with R02. Use the Sandbox test
user `user_good` / `pass_good` in Link.

## Payment lifecycle and order statuses

| PayBridge state (`_pbfp_payment_state`) | Meaning | WooCommerce order |
|---|---|---|
| `intent_created` / `intent_pending` | Transfer Intent created, Link not completed | `pending` |
| `intent_failed` | Customer's authorization failed (e.g. NSF risk) | stays `pending`, customer may retry |
| `transfer_created`, `pending`, `posted` | ACH debit submitted | `on-hold` |
| `settled` | Settled at the network | `on-hold` (or paid when "Mark order paid when" = settled) |
| `funds_available` | Funds available in the Plaid balance | `payment_complete()` → `processing`/`completed` |
| `failed` | Transfer failed | `failed` |
| `cancelled` | Transfer cancelled | `cancelled` |
| `returned` | ACH return after settlement (R01, R02, …) | `failed` + admin alert + merchant email |
| `manual_review` | Unexpected situation (e.g. money moving for a cancelled order, amount mismatch) | `on-hold` + admin alert |

Transitions, ranks and conflict rules: [`docs/STATE_MACHINE.md`](docs/STATE_MACHINE.md) and
[`docs/PAYMENT_LIFECYCLE.md`](docs/PAYMENT_LIFECYCLE.md). Extension hooks:
`paybridge_plaid_payment_state_changed`, `paybridge_plaid_payment_confirmed`,
`paybridge_plaid_payment_failed`, `paybridge_plaid_payment_cancelled`,
`paybridge_plaid_payment_returned`, `paybridge_plaid_payment_manual_review`.

## Webhooks, event sync and reconciliation

- `POST /wp-json/paybridge-for-plaid/v1/webhook` accepts only bodies signed by Plaid:
  ES256 JWT in `Plaid-Verification`, key fetched by `kid` from
  `/webhook_verification_key/get` (cached, rate-limited, negative-cached), `iat` at most
  5 minutes old, constant-time SHA-256 body hash comparison. Invalid webhooks return an
  error and never touch orders; key-lookup failures and Plaid rate limiting return 503 so
  Plaid retries.
- A valid `TRANSFER_EVENTS_UPDATE` schedules `/transfer/event/sync` through Action Scheduler
  (group `paybridge-for-plaid`, hook `paybridge_plaid_transfer_event_sync`). The cursor
  (`after_id`) advances only after events are durably stored.
- Transfers that do not belong to this store (another integration or store on the same
  Plaid account, another environment, an unknown attempt) are recorded as `ignored`
  with a reason and never retried.
- Reconciliation (`paybridge_plaid_reconcile`, every 15 minutes) re-reads intents and
  transfers that have been open for too long.

See [`docs/WEBHOOKS_AND_EVENTS.md`](docs/WEBHOOKS_AND_EVENTS.md).

## WooCommerce integration

- **HPOS**: declared compatible; all order data goes through WooCommerce CRUD
  (`$order->get_meta()` / `update_meta_data()` / `save()`), never `wp_posts`/`wp_postmeta`.
  Payment lookups by intent/transfer ID use PayBridge's own indexed table, so they work in
  both storage modes. Writes are verified after `save()` because WooCommerce swallows
  storage exceptions.
- **Checkout Blocks** and **Classic checkout**: both place the order and redirect to the
  order-pay page, where Plaid Link runs (`woocommerce_receipt_paybridge_plaid`).
- Unpaid-order cleanup keeps orders whose bank authorization is still in progress.
- Refunds are not supported in 0.1.0 (ACH refunds require Plaid refunds with their own
  lifecycle); refund manually and record it in WooCommerce.

## Operations

```bash
wp paybridge-plaid status                  # versions, HPOS, Blocks, REST, schema, sync/reconciliation health
wp paybridge-plaid test-connection         # verify keys and Transfer readiness
wp paybridge-plaid sync-events             # run /transfer/event/sync now
wp paybridge-plaid reconcile               # re-check open payments now
wp paybridge-plaid sync-order <order-id>   # re-read one order's intent/transfer from Plaid
wp paybridge-plaid fire-sandbox-webhook [--url=<https-url>]   # Sandbox only
```

Also available: **WooCommerce → PayBridge diagnostics** (the same report as `status`,
including the last verified and the last rejected webhook), Site
Health tests, a PayBridge panel on the order screen (payment state, intent/transfer IDs and
statuses, last event, failure/return reason, and a **Sync with Plaid** button), and persistent
admin notices for returns and manual review. Incident procedures: [`docs/RUNBOOKS.md`](docs/RUNBOOKS.md).

## Security model

- The Plaid secret stays on the server: it is never sent to the browser, never logged, never
  rendered in settings; logs pass through a redactor.
- Only the endpoints in `PlaidClient::ALLOWED_PATHS` can be called; `/sandbox/*` is blocked in
  Production.
- Customer REST endpoints require the order key plus a session binding and a per-order
  payment nonce; the browser never sends amounts or intent IDs.
- Webhooks are verified as described above; replays are harmless because events are
  idempotent.
- The webhook JWT library (`firebase/php-jwt`) is bundled under the
  `PayBridge\Plaid\Vendor\` namespace (Strauss) to avoid conflicts with other plugins.

Threat model and controls: [`docs/SECURITY.md`](docs/SECURITY.md).

## Privacy and external services

The plugin connects to Plaid (`https://sandbox.plaid.com` or `https://production.plaid.com`)
and loads Plaid Link from `https://cdn.plaid.com/link/v2/stable/link-initialize.js` on the
order-pay page. It sends the order amount, a short description, the customer's billing name
and email address, and PayBridge identifiers (order ID, attempt ID, environment, a hashed
store marker). Bank account
credentials are entered only in Plaid Link and never reach the store. Plaid:
[Terms](https://plaid.com/legal/), [Privacy Policy](https://plaid.com/legal/#end-user-privacy-policy).
A privacy-policy text is added to **Settings → Privacy**. Details:
[`docs/PRIVACY_COMPLIANCE.md`](docs/PRIVACY_COMPLIANCE.md).

## Development

Prerequisites: PHP 8.1+ with `mysqli`, Composer 2, Node.js 22, WP-CLI 2.x, MySQL/MariaDB,
`zip`/`unzip`.

```bash
composer install     # dev tools; also regenerates vendor-prefixed/ via Strauss
npm ci
npm run build        # Parcel: TypeScript + SCSS → assets/
npm run plugin-zip   # build + dist/paybridge-for-plaid-<version>.zip
```

`vendor-prefixed/` (the prefixed runtime dependency) is committed so releases do not need
Composer; CI fails if it is out of date. Front-end sources live in `resources/ts` and
`resources/scss`; compiled `assets/` are gitignored and produced by the build.

## Testing

| Command | What it covers |
|---|---|
| `composer syntax` / `composer lint` / `composer stan` | PHP lint, PHPCS, PHPStan level 6 |
| `composer test` | PHPUnit: money, snapshot, state machine, Plaid client/DTOs/services, webhook verification, redaction |
| `npm test` | Build, TypeScript typecheck, behavioural tests of the payment-page script |
| `bash scripts/test-integration.sh` | Fresh WordPress + WooCommerce from the release ZIP; HPOS off and on; payment flows, ambiguous failures, foreign transfers, webhook REST matrix, parallel-request concurrency, uninstall |
| `bash scripts/test-browser-e2e.sh` | Playwright: Classic and Blocks checkout, Link success/failure/exit, double submit, guest and logged-in customers |
| `bash scripts/test-plugin-check.sh` | WordPress Plugin Check on the release ZIP |
| `npm run test:sandbox` | Optional real Plaid Sandbox run: genuine Transfer UI from the Checkout block and the Classic checkout, $11.11/$22.22/$33.33 lifecycles (needs `PAYBRIDGE_PLAID_SANDBOX_*`; exits 78 when absent) |
| `npm run test:sandbox:ngrok` | The same run with the store on a public HTTPS URL through ngrok (`PAYBRIDGE_PLAID_NGROK_DOMAIN`): lifecycles driven by genuine Plaid-signed webhooks, then forged, tampered, replayed and stale webhooks sent through the tunnel |

All scripts create a throwaway site and database (`paybridge_test_*`, `paybridge_browser_*`,
`paybridge_check_*`, `paybridge_sandbox_*`), install only the release ZIP and delete both
afterwards. `scripts/lib/test-env.sh` loads the gitignored `.env` (see
[`.env.example`](.env.example)) and falls back to `~/.my.cnf` for the database credentials;
optional `PAYBRIDGE_PLAID_TEST_WP_VERSION` / `PAYBRIDGE_PLAID_TEST_WC_VERSION` select the
versions. Plaid is replaced by a deterministic mock in all but the Sandbox scripts.

### Public-HTTPS webhook gate (ngrok)

```bash
ngrok config add-authtoken <token>        # once; or export NGROK_AUTHTOKEN
echo 'PAYBRIDGE_PLAID_NGROK_DOMAIN=ocelot-dribble-creature.ngrok-free.dev' >> .env
npm run plugin-zip && npm run test:sandbox:ngrok
```

The store is installed at `https://<domain>`; `ngrok http` forwards to the PHP built-in
server. The gate first processes the Plaid account's historical events (all must be ignored
as other stores', without touching this store), runs the three Transfer UI payments ($11.11
through the Checkout block, $22.22 and $33.33 through the Classic checkout), asks Plaid to send a
signed `TRANSFER_EVENTS_UPDATE` to the public webhook URL
(`wp paybridge-plaid fire-sandbox-webhook`), and requires the lifecycle to be completed only
by that webhook → Action Scheduler → `/transfer/event/sync`. It then sends missing, malformed,
tampered-body, flipped-signature, `alg: none`, HS256-confusion, attacker-signed (Plaid key ID
and unknown key ID) and oversized requests through the tunnel, replays the genuine webhook
(accepted, harmless) and, after five minutes, replays it again (rejected as stale).
`PAYBRIDGE_PLAID_SKIP_STALE_REPLAY=1` skips the five-minute wait. A reserved ngrok domain can
be online in only one ngrok agent at a time.

## CI and releases

`.github/workflows/quality.yml` runs on pushes and pull requests to `master`: static analysis
and front-end tests, PHPUnit on PHP 8.1–8.4, package verification, integration on
WordPress 6.6/WooCommerce 8.5.2 and WordPress 7.1/WooCommerce 11.1.0, Plugin Check, browser
E2E and the optional Sandbox gate (repository secrets, never for forks), followed by a single
**Quality Gate** job.

`.github/workflows/release.yml` runs for tags `vX.Y.Z` on `master`: it checks that the tag,
plugin header, `PAYBRIDGE_PLAID_VERSION` and `readme.txt` Stable tag agree, builds the ZIP
with `scripts/package.sh` and publishes a GitHub release. See
[`docs/CI_CD_RELEASE.md`](docs/CI_CD_RELEASE.md).

## Project layout

```
paybridge-for-plaid.php   bootstrap: header, constants, autoloaders, activation hooks
uninstall.php             conservative, opt-in cleanup of PayBridge-owned data only
src/
  Admin/                  settings connection test, order meta box, diagnostics, Site Health, notices
  Background/             Action Scheduler jobs: event sync, reconciliation
  Checkout/               order-pay page, customer access checks, Blocks payment method
  CLI/                    wp paybridge-plaid commands
  Gateway/                WC_Payment_Gateway, availability rules
  Logging/                WooCommerce logger wrapper + redactor
  Payment/                snapshot, state machine, attempt/completion services, event processor, projector
  Persistence/            installer, payment reservations, event store, cursor, DB mutex
  Plaid/                  HTTP client, DTOs, intent/link/transfer/event services, webhook verification
  REST/                   /link-token, /complete, /webhook
  Settings/, Support/, Exception/
resources/                TypeScript and SCSS sources, images
vendor-prefixed/          firebase/php-jwt under PayBridge\Plaid\Vendor\
tests/                    Unit, Integration (WP-CLI), E2E (Playwright), js, fixtures
scripts/                  package, integration, browser, Plugin Check, Sandbox scripts
docs/                     engineering documentation, ADRs, API integration maps
```

## Documentation

Start with [`docs/README.md`](docs/README.md). Most relevant:
[Development handbook](docs/DEVELOPMENT_HANDBOOK.md) ·
[Architecture](docs/ARCHITECTURE.md) ·
[Plaid Transfer contract](docs/api/PLAID_TRANSFER.md) ·
[Security](docs/SECURITY.md) ·
[Concurrency & idempotency](docs/CONCURRENCY_IDEMPOTENCY.md) ·
[Data model](docs/DATA_MODEL.md) ·
[Testing](docs/TESTING_QA.md) ·
[Runbooks](docs/RUNBOOKS.md) ·
[ADRs](docs/adr/README.md).

Contributor and agent rules: [`AGENTS.md`](AGENTS.md).
