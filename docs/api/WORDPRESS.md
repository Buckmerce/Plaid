# WordPress Core API — PayBridge Integration Contract

> This document describes which WordPress core APIs PayBridge uses and how.
> It is not a substitute for the official WordPress developer documentation.
>
> **Official source:** <https://developer.wordpress.org/>
> **Last verified:** 2026-09-30

---

## 1. Plugin lifecycle hooks

| Hook | Handler | Purpose |
|---|---|---|
| `register_activation_hook` | `Installer::activate()` | Creates database tables (`dbDelta`) |
| `register_deactivation_hook` | `Scheduler::unschedule_all()` | Removes Action Scheduler tasks |
| `plugins_loaded` (priority 20) | `Bootstrap::boot()` | Main plugin initialisation |
| `before_woocommerce_init` | Compatibility declarations | HPOS + Blocks compatibility |

**Source:** [`paybridge-for-plaid.php`](../../paybridge-for-plaid.php)

---

## 2. REST API

### Routes registered

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/paybridge-for-plaid/v1/link-token` | `POST` | `authorize_payer` | Create Plaid Link token |
| `/paybridge-for-plaid/v1/complete` | `POST` | `authorize_payer` | Verify payment completion |
| `/paybridge-for-plaid/v1/webhook` | `POST` | `__return_true`¹ | Receive Plaid webhooks |

¹ The webhook route is open at the HTTP layer. Authentication is performed inside the callback via ES256 JWT verification — not via WordPress nonces.

### Route arguments schema

Customer-facing routes (`link-token`, `complete`) require:

| Argument | Type | Validation |
|---|---|---|
| `order_id` | integer | `minimum: 1` |
| `order_key` | string | `pattern: ^wc_order_[A-Za-z0-9]{1,40}$` |
| `payment_nonce` | string | `pattern: ^[a-f0-9]{10}$` |

### Authorization (`authorize_payer`)

1. Fetch order by `order_id`
2. Ensure WooCommerce session is initialised (for guests)
3. Verify `order_key` matches order
4. Verify `payment_nonce` via `wp_verify_nonce()`
5. For logged-in users: verify `get_current_user_id()` matches order customer
6. For guests: verify session-based grant (HMAC fingerprint)

**Source:** [`RestRoutes`](../../src/REST/RestRoutes.php), [`PaymentAccess`](../../src/Checkout/PaymentAccess.php)

---

## 3. Options API

| Option name | Purpose | Autoload |
|---|---|---|
| `woocommerce_paybridge_plaid_settings` | Gateway settings (managed by WooCommerce) | yes |
| `paybridge_plaid_schema_version` | Database schema version | yes |
| `paybridge_plaid_event_cursor_<env>_<account>` | `/transfer/event/sync` cursor of one Plaid account (ADR-0018) | no |
| `paybridge_plaid_first_intent_at_<env>_<account>` | Payment epoch of one Plaid account (ADR-0011, ADR-0018) | no |
| `paybridge_plaid_event_sync_<env>_<account>` | Event-sync health of one Plaid account: last sync, last error, consecutive failures | no |
| `paybridge_plaid_last_connection_test` | Last connection test result | no |
| `paybridge_plaid_last_reconciliation`, `_error` | Last reconciliation run / error | no |
| `paybridge_plaid_payment_alerts`, `paybridge_plaid_last_webhook`, `_rejection`, `paybridge_plaid_last_link_token_error` | Alerts and diagnostics | no |

`<account>` is the 16-hex, non-secret account fingerprint (ADR-0015). Schema-2 per-environment
options (`paybridge_plaid_event_cursor_<env>`, `paybridge_plaid_first_intent_at_<env>`,
`paybridge_plaid_last_event_sync[_error]`, `paybridge_plaid_event_sync_failures`) are kept after an
upgrade for auditing only and are never read by the runtime. Full list: `docs/DATA_MODEL.md`.

**Source:** [`Settings`](../../src/Settings/Settings.php), [`EventCursor`](../../src/Persistence/EventCursor.php), [`EventSyncService`](../../src/Background/EventSyncService.php), [`ConnectionTester`](../../src/Admin/ConnectionTester.php)

---

## 4. Transients API

| Transient key pattern | Purpose | TTL |
|---|---|---|
| `pbfp_jwk_<env>_<hash>` | Cached webhook verification JWK | 24 hours |
| `pbfp_jwk_<env>_<hash>` (negative) | Unknown key ID negative cache | 5 minutes |
| `pbfp_jwk_fetches_<env>_<minute>` | JWK fetch rate limiter | 2 minutes |
| `pbfp_rl_link_token_<order_id>` | Link token creation rate limiter | 10 minutes |
| `pbfp_connection_test_<user_id>` | Connection test result for admin flash | 10 minutes |

**Source:** [`VerificationKeyProvider`](../../src/Plaid/Webhook/VerificationKeyProvider.php), [`RestRoutes`](../../src/REST/RestRoutes.php), [`ConnectionTester`](../../src/Admin/ConnectionTester.php)

---

## 5. Database / wpdb

PayBridge creates three custom tables (schema 3). It never reads or modifies tables owned by other plugins.

| Table | Purpose |
|---|---|
| `{$wpdb->prefix}paybridge_plaid_events` | Durable transfer event store per Plaid account (deduplication, leasing, processing) |
| `{$wpdb->prefix}paybridge_plaid_payment_locks` | Payment reservation locks and payment index (idempotency, concurrent access) |
| `{$wpdb->prefix}paybridge_plaid_refunds` | Refund reservations and Plaid refund identities per Plaid account |

### Schema management

- Tables are created via `dbDelta()` from `wp-admin/includes/upgrade.php`
- Schema is verified by checking every column, NOT NULL account columns, every unique index and the absence of the schema-2 environment-only identities after `dbDelta()`
- Schema 2 → 3 steps the WordPress API cannot express (`ALTER TABLE … MODIFY/DROP INDEX` on PayBridge tables) run explicitly and idempotently
- Version stored in `paybridge_plaid_schema_version` option
- Checked on every request; re-run if version mismatch

### Mutex (DatabaseMutex)

Uses `GET_LOCK()` / `RELEASE_LOCK()` / `IS_USED_LOCK()` MySQL advisory locks (hashed, site-scoped names) for serialising payment, refund, reconciliation and per-account event-sync workers.

**Source:** [`Installer`](../../src/Persistence/Installer.php), [`DatabaseMutex`](../../src/Persistence/DatabaseMutex.php)

---

## 6. HTTP API

| Function | Usage |
|---|---|
| `wp_remote_post()` | Plaid API calls (via `PlaidClient::wordpress_transport()`) |
| `wp_remote_retrieve_response_code()` | HTTP status extraction |
| `wp_remote_retrieve_body()` | Response body extraction |

All external HTTP communication goes through `PlaidClient`, which is the single point of Plaid API access.

**Source:** [`PlaidClient`](../../src/Plaid/Client/PlaidClient.php)

---

## 7. Security APIs

| API | Usage |
|---|---|
| `wp_create_nonce()` | Payment nonce for REST route auth, admin CSRF |
| `wp_verify_nonce()` | REST route payment nonce validation |
| `check_admin_referer()` | Connection test CSRF check |
| `wp_nonce_url()` | Connection test button URL |
| `current_user_can('manage_woocommerce')` | Admin page access, connection test, notices |
| `wp_salt('auth')` | HMAC key for guest session fingerprint |
| `hash_hmac()` | Guest order access fingerprint |
| `hash_equals()` | Constant-time comparison for order keys, session grants, body hashes |

**Source:** [`PaymentAccess`](../../src/Checkout/PaymentAccess.php), [`ConnectionTester`](../../src/Admin/ConnectionTester.php), [`WebhookVerificationService`](../../src/Plaid/Webhook/WebhookVerificationService.php)

---

## 8. Admin pages

| Page | Slug | Hook | Capability |
|---|---|---|---|
| Diagnostics | `paybridge-plaid-diagnostics` | `admin_menu` (under WooCommerce) | `manage_woocommerce` |
| Gateway settings | Via WooCommerce Settings → Payments | `woocommerce_page_wc-settings` | `manage_woocommerce` |

### Admin hooks used

| Hook | Handler | Purpose |
|---|---|---|
| `admin_menu` | `DiagnosticsPage::register()` | Diagnostics page |
| `admin_post_pbfp_test_connection` | `ConnectionTester::handle()` | Connection test action |
| `admin_enqueue_scripts` | `PayBridgeGateway::enqueue_admin_assets()` | Admin CSS/JS |
| `admin_notices` | `AdminNotices::register()` | Configuration warnings |
| `admin_init` | `Plugin::privacy_policy()` | Privacy policy suggestion |
| `plugin_action_links_*` | `Plugin::action_links()` | Settings + Diagnostics links |

---

## 9. Script / style enqueuing

### Frontend (payment page)

| Handle | Source | Dependencies |
|---|---|---|
| `paybridge-plaid-link` | Plaid CDN: `https://cdn.plaid.com/link/v2/stable/link-initialize.js` | none |
| `paybridge-plaid-payment` | `assets/build/payment-page.js` | `paybridge-plaid-link` |
| `paybridge-plaid-payment` (CSS) | `assets/payment-page.css` | none |

### Frontend (Checkout Blocks)

| Handle | Source | Dependencies |
|---|---|---|
| `paybridge-plaid-blocks` | `assets/build/blocks.js` | `wc-blocks-registry`, `wc-settings`, `wp-element`, `wp-html-entities`, `wp-i18n` |

### Admin

| Handle | Source | Condition |
|---|---|---|
| `paybridge-plaid-admin` (JS) | `assets/build/admin-settings.js` | Gateway settings page only |
| `paybridge-plaid-admin` (CSS) | `assets/admin-settings.css` | Gateway settings page only |

**Source:** [`PaymentPage`](../../src/Checkout/PaymentPage.php), [`PayBridgePaymentMethod`](../../src/Checkout/PayBridgePaymentMethod.php), [`PayBridgeGateway`](../../src/Gateway/PayBridgeGateway.php)

---

## 10. Privacy API

PayBridge registers a privacy policy suggestion via `wp_add_privacy_policy_content()` on `admin_init`.

**Source:** [`Plugin::privacy_policy()`](../../src/Plugin.php)

---

## 11. Site Health

PayBridge adds diagnostic tests to the WordPress Site Health screen via the `site_status_tests` filter.

**Source:** [`SiteHealth`](../../src/Admin/SiteHealth.php)

---

## 12. Autoloading

PayBridge uses a custom `spl_autoload_register()` for the `PayBridge\Plaid\` namespace. Vendor dependencies are namespace-prefixed under `PayBridge\Plaid\Vendor\` and loaded from `vendor-prefixed/autoload.php`.

**Source:** [`paybridge-for-plaid.php`](../../paybridge-for-plaid.php)

---

## 13. Freshness protocol

Before modifying WordPress core API usage:

1. Read this document
2. Read the official WordPress developer docs for the API
3. Verify the API is available in the minimum supported WordPress version (6.6)
4. Update this document if the external contract changed
5. Run tests after changes
