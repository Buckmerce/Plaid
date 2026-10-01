# Testing and QA Strategy

## 1. Philosophy

Payment quality is tested by failure modes, concurrency and recovery—not only by line coverage.

## 2. Unit tests

Required domains:

- money normalization/comparison;
- payment snapshot;
- state machine;
- transition ordering;
- gateway availability;
- provider error mapping;
- Plaid DTO parsing;
- idempotency decisions;
- lock acquisition/recovery;
- event deduplication;
- webhook verification helpers;
- log redaction;
- order projection decisions.

## 3. Integration tests

Use disposable WordPress/WooCommerce installation.

Cover:

- activation/schema;
- settings;
- gateway registration;
- HPOS CRUD;
- Classic order flow;
- Blocks registration/server data;
- REST endpoint permissions;
- custom event/lock tables;
- Action Scheduler jobs;
- reconciliation;
- uninstall ownership boundaries.

## 4. Contract tests

Mock Plaid at HTTP boundary with fixtures based on current official schemas.

Test:

- successful response;
- Plaid structured error;
- HTTP error;
- timeout;
- malformed JSON;
- missing required response field;
- wrong environment/correlation.

Fixtures must be minimal and sanitized.

## 5. E2E/browser tests

Use Playwright for:

- gateway settings UX;
- Classic Checkout;
- Checkout Blocks;
- guest checkout;
- logged-in checkout;
- order-pay page;
- payment page;
- Link-launch readiness;
- duplicate button protection;
- exit/retry UX;
- error UX.

Real Link automation may require special handling; do not replace provider truth with fake claims.

## 6. Real Sandbox tests

When credentials are securely available, test current documented scenarios.

As of the documentation verification date (2026-10-01):

- `$11.11`: success path through funds available for ACH debit;
- `$22.22`: failure;
- `$33.33`: funds available then R01 return (and the order is never debited again, ADR-0019);
- refunds: `$1.11` pending → posted → settled → returned, `$2.22` failed; other refunds stay pending
  until `/sandbox/transfer/refund/simulate`.

Always re-check current Plaid docs before relying on these values.

## 7. Concurrency suite

Must simulate:

- two parallel payment initiations;
- duplicate completion calls;
- lock expiry;
- event worker collision;
- job retry;
- provider timeout with ambiguous result;
- DB write failure after remote success where feasible.

## 8. Security suite

Test:

- wrong order key;
- other user's order;
- missing nonce;
- low capability admin request;
- invalid webhook signature;
- modified webhook body;
- wrong JWT algorithm;
- stale JWT;
- duplicate event;
- malicious output strings escaped.

## 9. Regression rule

Every production bug receives a regression test before or with the fix.

## 10. Definition of green

CI green means the checks actually ran. Skipping a required suite due to accidental configuration is a failure, not success.

## 11. Implemented suites (1.0)

| Suite | Command | Location | Scope |
|---|---|---|---|
| PHP static | `composer validate --strict`, `composer syntax`, `composer lint`, `composer stan` | `phpcs.xml.dist`, `phpstan.neon` | Lint, PSR-12 + forbidden functions (`get_post_meta`, `var_dump`, …), PHPStan level 6 with WordPress/WooCommerce stubs |
| Unit | `composer test` | `tests/Unit/` (212 tests) | Decimal/Money (cent arithmetic), snapshot, payment and refund state machines incl. the explicit ordering matrices, monitoring/return-window policy, refund policy (limits, remaining amount, 10 refunds, 180 days, account/environment), idempotency keys, Plaid client (errors, timeouts, malformed JSON, allowlist, Sandbox guard), DTOs (return windows, cancellable, refunds, refund events never lifecycle events), refund API contract, webhook verification, connection-error classification, background error categories and backoff, attempt-history retention, settings (fixed WEB class, statement descriptor, Link customization required in both environments, account identity), account-scoped cursor and epoch (per account, rotation keeps the stream), return retry policy matrix (R01/R09 Retry 1/2 and limit, R10/R07/R11/R02/unknown blocked, 180-day window, Transfer UI always blocked, failures are not returns), refund monitoring horizon, redactor |
| JavaScript | `npm test` | `tests/js/` | Built payment-page script in a VM: double click, no amount/intent in requests, guest vs logged-in nonce, Link success/NSF/exit/failed, manual review, HTTP errors, not-payable, rate limit, missing name |
| Integration | `bash scripts/test-integration.sh` | `tests/Integration/`, `tests/fixtures/plaid-mock.php` (per-client streams, transfers and refunds like real Plaid clients) | Fresh WP + WC with ONLY the release ZIP; every suite with HPOS off and on: smoke (schema 3, v1→v3 migration, account-scoped unique keys, settings, availability incl. Link customization in both environments, Blocks, CLI, documented options), payment flow (Sandbox scenarios, idempotency, ambiguity, access control, reconciliation, foreign transfers), webhook REST matrix, **refunds** (full, partial, limits, double submit, retried `process_refund`, `refunded_payment` recorded, failure $2.22, return $1.11, timeout/502/unknown outcome, adoption by event, remote success + local write failure, reconciliation, external refunds, return after refund), **lifecycle** (legal name, statement descriptor, disabled gateway keeps monitoring/returns/reconciliation, return windows, account guard, Link customization, returned payment semantics and **no re-debit after a return** (also after the order was switched to another payment method), retry after a failure, merchant cancel, manual review), **accounts** (production A through event 1000 → B starting at 1, cursors/epochs/diagnostics per account, identical event and refund IDs coexist, signed B webhook only touches B, secret rotation keeps the stream, Sandbox A → B), **maintenance** (enabled, disabled with open payment/refund/backlog/deferred event/failed sync, truly idle stops, webhook while stopped), **migration** (schema 2 → 3 with payments, refunds, returns, events, cursor, epoch; idempotent; stream re-read without duplicates), multi-process concurrency (parallel `process_payment`, concurrent completions, parallel event workers, parallel refunds), uninstall ownership |
| Package | `npm run plugin-zip && bash scripts/verify-package.sh` | `scripts/` | Required runtime files, every `src/**/*.php` packaged, compiled assets byte-identical to a fresh build, no dev files/source maps/local paths/secrets, PHP lint, SHA-256 |
| Pristine install | `bash scripts/test-package-smoke.sh` | — | WordPress + WooCommerce + the ZIP only, on a **fresh HPOS store and a fresh legacy-storage store** (storage chosen before any order exists; no forced authoritative-storage switch): activation, schema, settings/status, gateway + refunds support, REST routes, Blocks, Action Scheduler, diagnostics, Site Health, order panel, deactivation, default uninstall keeps data, zero PHP warnings/notices/deprecations |
| Docs and source audit | `bash scripts/check-docs.sh`, `bash scripts/audit-source.sh [zip]` | — | Mandatory documentation and every ADR tracked, relative links resolve; the source archive and given archives contain no `.env`, traces, IDE state, caches or credentials; `.env.example` placeholders only; local `.env` credential values never in Git |
| Browser E2E | `bash scripts/test-browser-e2e.sh` | `tests/E2E/browser-smoke.js` | Playwright CLI: settings/status/diagnostics, Classic and Blocks checkout for guest and logged-in customers, payment page (success, failure, exit, retry, double click, keyboard, loading state), Plaid Link unavailable, misconfigured gateway and EUR not offered, already-paid and cancelled orders, WooCommerce admin refund through Plaid, returned payment badge/alert/panel, **blocked re-debit of a returned order** (not offered on the pay link, explained, no intent or Link token), **axe-core WCAG 2.2 AA scans** of every PayBridge-owned UI state, focus restoration after Link exit. A failure names the phase reached and prints the server log, `debug.log`, the server status and a core-dump backtrace. The runner passes only when the suite returns its final success marker (a native browser dialog would otherwise end `playwright-cli run-code` early with exit 0); dialogs are answered in-page. The web server's PHP settings are part of the environment (`tests/fixtures/php-server/`): OPcache's JIT stays at PHP's default (off), because PHP 8.2's built-in server workers crash with the tracing JIT that setup-php enables by default |
| Plugin Check | `bash scripts/test-plugin-check.sh` | — | WordPress Plugin Check on the release ZIP; fails on any finding except the documented trademark warning |
| Real Sandbox | `npm run test:sandbox` | `tests/E2E/sandbox-transfer-ui.js`, `sandbox-refunds.php` | Genuine Plaid Sandbox + Transfer UI with the Sandbox Link customization (single-account Account Select asserted): $11.11 (Checkout block) → paid, $22.22 after a genuine Link exit → failed, $33.33 → returned R01 and refused as a same-order re-debit, second $11.11 → paid; refunds: $1.11 → returned, $2.22 → failed, $5.00 with a dropped Plaid response recovered through Plaid idempotency (one refund at Plaid), PayBridge's exact remaining amount with refunds in flight ($2.78) and an over-refund refused without any Plaid call (WooCommerce's own limit answers first here; PayBridge's stricter limit is covered by the refunds integration suite), full refund $11.11 → settled; reconciliation agrees; duplicate events and completion callbacks harmless. Exit 78 when any of `PAYBRIDGE_PLAID_SANDBOX_CLIENT_ID`, `_SECRET`, `_USERNAME`, `_PASSWORD`, `_LINK_CUSTOMIZATION` is missing (a release fails on it) |
| Real Sandbox, public HTTPS | `npm run test:sandbox:ngrok` | + `tests/E2E/sandbox-webhooks.php` | Same through `https://$PAYBRIDGE_PLAID_NGROK_DOMAIN`; lifecycles and refund events driven by genuine Plaid-signed webhooks; forged/tampered/replayed/stale webhook matrix |

Disposable databases are named `paybridge_test_*`, `paybridge_browser_*`,
`paybridge_check_*`, `paybridge_sandbox_*`; scripts refuse to drop anything else.

### Database and version matrix locally

The integration script takes its stack from the environment, so the CI matrix can be
reproduced with database containers (any MySQL 8.0/8.4 or MariaDB 10.11/11.4 server):

```bash
docker run -d --name pbfp-mariadb114 -e MARIADB_ROOT_PASSWORD=<local-only> -p 127.0.0.1:33114:3306 mariadb:11.4
PAYBRIDGE_PLAID_TEST_DB_HOST=127.0.0.1:33114 PAYBRIDGE_PLAID_TEST_DB_USER=root \
PAYBRIDGE_PLAID_TEST_DB_PASSWORD=<local-only> \
PAYBRIDGE_PLAID_TEST_WP_VERSION=6.6 PAYBRIDGE_PLAID_TEST_WC_VERSION=8.7.0 \
PAYBRIDGE_PLAID_TEST_PLUGIN_ZIP=dist/paybridge-for-plaid-<version>.zip \
bash scripts/test-integration.sh
```

For a MySQL 8.0 server with a MariaDB command-line client (e.g. XAMPP), start the container with
`--default-authentication-plugin=mysql_native_password`. To reproduce CI's PHP engine settings in
the browser suite (GitHub's setup-php enables OPcache with tracing JIT for the `wp server` workers),
put an ini that loads `opcache` with `opcache.jit=1235` and `opcache.jit_buffer_size=256M` in a
directory listed in `PHP_INI_SCAN_DIR` (loaded before the suite's fixture, like setup-php's php.ini),
or in `PAYBRIDGE_PLAID_E2E_PHP_INI_DIR` (loaded after it, to reproduce the JIT crash on purpose).

Other PHP versions: run the same script inside a `php:<version>-cli` image with the `mysqli`
extension, a MySQL/MariaDB client and WP-CLI (with a MariaDB 11.x client and a MariaDB server
without TLS, put `skip-ssl` in the `[client]` section of the container user's `~/.my.cnf`).

## 12. Release acceptance matrix

| Gate | Classic Checkout | Checkout Blocks |
|---|---|---|
| Guest checkout | browser, integration | browser, real Sandbox ($11.11) |
| Logged-in checkout | browser, integration | browser |
| Successful payment | browser, integration, real Sandbox | browser, real Sandbox |
| Failed payment | browser, integration, real Sandbox ($22.22) | browser |
| Returned payment | browser (badge/alert/panel), integration, real Sandbox ($33.33) | integration |
| Retry / customer exits Link | browser, real Sandbox (genuine Link exit) | browser |
| Already-paid / cancelled order | browser, integration | shared payment page |
| Unsupported currency / misconfigured gateway | browser, integration | browser |
| Native refunds (full, partial, failure, return) | browser (admin UI), integration (WC 8.7.0, 9.9.5, 11.1.2; MySQL and MariaDB; HPOS on/off), real Sandbox | same order screen |
| No re-debit after a return | browser, integration, real Sandbox ($33.33) | shared payment page / order-pay |
| Plaid account switch | integration (accounts suite) | — |
| HPOS on / off | integration, pristine install (fresh store per mode) | integration, pristine install |
