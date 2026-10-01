=== Buckmerce – Bank Payments via Plaid for WooCommerce ===
Contributors: al5dy
Tags: woocommerce, pay by bank, ach, bank transfer, plaid
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Pay by Bank payments and native refunds for WooCommerce using Plaid Transfer: customers pay directly from their US bank account.

== Description ==

Buckmerce for Plaid adds a **Pay by Bank** payment method to WooCommerce. Customers connect their bank and authorize a one-time ACH debit in Plaid's Transfer UI. Buckmerce creates the payment on your server, verifies every result with Plaid, keeps the WooCommerce order in step with the ACH lifecycle until the ACH return window closes, and refunds payments back to the customer's bank.

Buckmerce for Plaid is an independent third-party integration. It is not developed, endorsed or supported by Plaid Inc. You need your own Plaid account with Plaid Transfer enabled.

= Features =

* Pay by Bank for Classic Checkout and the Checkout block.
* Plaid Transfer UI: Plaid shows the payment details and captures the customer's authorization (Nacha WEB).
* Server-authoritative payments: the amount always comes from the WooCommerce order, and success is confirmed only by Plaid's API — never by the browser.
* ACH lifecycle tracking from Plaid transfer events: pending, posted, settled, funds available, failed, cancelled and returned.
* Monitoring until Plaid's unauthorized return window closes (about three months), even if you disable the gateway.
* Native WooCommerce refunds through Plaid: full and multiple partial refunds, protected against duplicates, with refund status tracking.
* ACH returns are impossible to miss: private order note, order marked Failed with its payment history kept, "Bank payment returned" badge in the orders list, admin notice and email — a critical alert if the payment was already refunded.
* Customers can pay a failed order again; every attempt stays in the order's payment history. A returned bank payment is never debited again (Plaid restricts reprocessing returned transfers); the store collects it another way.
* Webhooks are verified cryptographically (ES256 JWT and body hash) before anything happens.
* Background reconciliation recovers missed webhooks, stale payments and unconfirmed refunds.
* Duplicate-payment protection with database-backed payment reservations.
* Protection against switching Plaid accounts while payments are still monitored.
* Order panel with Plaid identifiers, return windows, refunds, "Sync with Plaid" and "Cancel bank payment" while Plaid allows it.
* Configuration status, diagnostics page, Site Health checks and redacted logs.
* HPOS (High-Performance Order Storage) compatible.

= Requirements =

* WordPress 6.6 or later, WooCommerce 8.7 or later, PHP 8.1 – 8.4 with OpenSSL, MySQL 8.0+ or MariaDB 10.11+.
* A Plaid account with Plaid Transfer enabled (Sandbox for testing, Production approval for live payments).
* Store currency USD.
* HTTPS for Production.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin** and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → Payments → Buckmerce for Plaid**.
3. Choose the **Environment** (start with Sandbox) and enter the **Client ID** and **Secret** from the Plaid Dashboard (Developers → Keys).
4. Leave **Funding Account ID** empty if your Plaid Transfer account uses Plaid Ledger (the default).
5. In the Plaid Dashboard (in the same environment), create a Link customization with Account Select set to "Enabled for one account" and enter its name. It is required in Sandbox and Production.
6. Copy the **Webhook URL** shown on the settings page. In the Plaid Dashboard open Team Settings → Webhooks, add a webhook for "Transfer event" and paste the URL.
7. Click **Test connection**; when the status panel shows Ready, enable the gateway.

= Plaid account =

Buckmerce uses Plaid Transfer. Plaid must enable Transfer for your team; Production access requires Plaid's approval of your Transfer application. Plaid Transfer UI requires a Link customization with Account Select set to "Enabled for one account" in each environment you use (its language must match your store language). Refunds are paid from your Plaid Ledger's available balance.

= Sandbox =

In Sandbox no real money moves. Plaid simulates transfer outcomes by amount: an order total of $11.11 succeeds (pending → posted → settled → funds available), $22.22 fails, and $33.33 succeeds and is then returned (R01). Refunds of $1.11 are returned and $2.22 fail. Use the Plaid Sandbox test credentials shown in Plaid Link. The payment page shows a small "Sandbox" badge.

= Production =

Production moves real money. The site must use HTTPS; the gateway is hidden otherwise. Use the Production secret for the Production environment.

= Configuration =

* **Offer Pay by Bank at checkout** — controls new payments only; existing payments, returns and refunds are always monitored.
* **Link customization name** — required in Sandbox and Production (see above).
* **Bank statement description** — shown on the customer's bank statement after your company name (default PAYMENT, up to 10 letters/digits/spaces).
* **Payment network** — Same Day ACH (default) or Standard ACH.
* **Mark order paid when** — Funds are available (recommended) or the transfer is settled. Until then the order is On hold.
* **Debug logging** — redacted logs under WooCommerce → Status → Logs (source `buckmerce-plaid`).
* **Uninstall cleanup** — when enabled, deleting the plugin removes its settings, event and refund history and database tables. Payment records on orders are always kept.

The ACH class is always WEB (internet-authorized consumer debit), as required for Plaid Transfer UI. Customers must provide a billing first and last name (the bank account holder's legal name).

== Frequently Asked Questions ==

= Is this an official Plaid plugin? =

No. Buckmerce for Plaid is an independent third-party integration that uses the public Plaid API. It is not developed, endorsed or supported by Plaid Inc.

= When is an order marked as paid? =

When Plaid reports the transfer as funds available (or settled, if you choose that option). The customer finishing Plaid Link is not treated as proof of payment.

= What happens if a payment is returned? =

The order is marked Failed with a private note that includes the return code and reason, the orders list shows "Bank payment returned", an admin notice is shown until dismissed, and the site administrator receives an email. The original paid date, transaction ID and history are preserved. Buckmerce does not debit the customer's bank again for that order: Plaid allows reprocessing a returned payment only in narrow cases (R01/R09, marked retries) that Plaid Transfer UI cannot express, and never for unauthorized returns such as R10. Pay by Bank is therefore not offered for that order again; contact the customer and collect the payment another way. If you had already refunded the payment, Buckmerce cancels still-pending refunds and raises a critical alert, because you may lose both the payment and the refund.

= Can a customer be charged twice? =

Buckmerce keeps a single active Plaid Transfer Intent per order, protected by a database reservation, and Link tokens are issued only for that intent. Retries reuse it; a new one is created only after Plaid reports the previous attempt failed or it can no longer be authorized.

= Which currencies are supported? =

USD only.

= Can I refund a Pay by Bank payment? =

Yes. Use WooCommerce's Refund button on the order and choose the automatic refund through Buckmerce for Plaid. Full and multiple partial refunds are supported once the payment has settled (Plaid allows up to 10 refunds per payment within 180 days). Buckmerce prevents duplicate refunds and tracks each refund until it settles, fails or is returned.

= What happens if I disable the gateway or change Plaid keys? =

Disabling only hides Pay by Bank at checkout; existing payments keep being monitored. You can rotate your Plaid secret at any time, but Buckmerce refuses to switch to another Plaid Client ID or environment while Production payments are still monitored, because the old payments could no longer be checked for returns.

= What happens when I switch to another Plaid account? =

Each Plaid account has its own transfer-event history. Buckmerce keeps a separate event cursor and history per account and environment, so a new account's events are never mistaken for the old account's, and the old account's history stays available for auditing.

= Does it support subscriptions? =

No. Buckmerce 1.0 supports one-time payments.

== External services ==

This plugin connects to **Plaid** (Plaid Inc., https://plaid.com) to process Pay by Bank payments:

* **Plaid API** (`https://sandbox.plaid.com` or `https://production.plaid.com`) — called from your server to create and read Transfer Intents and transfers, create Link tokens, create, read and cancel refunds, cancel a transfer on your request, read transfer events and fetch webhook verification keys. Sent: your Plaid API credentials (server-to-server only), the order amount and currency, your bank statement description, the customer's billing name and email address, refund amounts and internal order references. When: at checkout, when the customer pays, when you refund or cancel, when Plaid sends a webhook, during background reconciliation and when an administrator syncs an order or tests the connection.
* **Plaid Link** (`https://cdn.plaid.com/link/v2/stable/link-initialize.js`) — loaded in the customer's browser on the payment page. The customer's bank connection and authorization happen inside Plaid's interface.
* **Plaid webhooks** — Plaid sends transfer notifications to your site's webhook URL.

Plaid terms: https://plaid.com/legal/ — Plaid end user privacy policy: https://plaid.com/legal/#end-user-privacy-policy

== Privacy ==

Buckmerce sends only the data needed to create the payment or refund (amount, statement description, billing name and email, order references) to Plaid. Bank login credentials and account numbers are handled by Plaid and are never received or stored by your store. Plaid payment identifiers and statuses are stored on the order for accounting. No telemetry is collected. Suggested privacy-policy text is added under Settings → Privacy.

== Security ==

* Plaid secrets are kept server-side, never rendered back in settings, never sent to the browser and redacted from logs.
* Webhooks are rejected unless the Plaid-Verification JWT (ES256), key ID, token age and request body hash all verify.
* Payment endpoints require the order key and the order owner's session; a numeric order ID alone never grants access.
* Every administrator action (sync, cancel, review decisions, alerts) requires the WooCommerce management capability and a nonce.
* Report security issues privately to the plugin author.

== Development and Source Code ==

The JavaScript and CSS files in `assets/` are compiled and minified. The complete human-readable source code of this plugin — the TypeScript and SCSS sources (`resources/`), the PHP code, the dependency definitions (`composer.json`, `composer.lock`, `package.json`, `package-lock.json`) and the build and test scripts — is available in the public source repository:

https://github.com/al5dy/buckmerce-plaid

To build the plugin from source, run `composer install` (installs the development tools and regenerates the namespace-prefixed library in `vendor-prefixed/` with Strauss), `npm ci` and `npm run plugin-zip` (compiles the assets with Parcel and builds the plugin ZIP). The repository's README describes the build and the test suites in detail.

The plugin bundles one third-party library: `firebase/php-jwt` (BSD-3-Clause), used to verify the signature of Plaid webhooks. It is prefixed into the `Buckmerce\Plaid\Vendor` namespace so it cannot conflict with another plugin's copy; its license is in `vendor-prefixed/firebase/php-jwt/LICENSE` and the plugin's `composer.json` declares it.

== Screenshots ==

1. Gateway settings with the status panel, webhook URL and connection test.
2. The Pay by Bank payment page with the "Connect bank and pay" button.
3. The Buckmerce panel on the WooCommerce order screen, with refunds and payment history.
4. The Buckmerce diagnostics page.

== Changelog ==

= 1.0.0 =
* New: native WooCommerce refunds through Plaid (full and multiple partial), protected against duplicates, with refund status tracking, reconciliation and alerts.
* New: payments are monitored until Plaid's unauthorized return window closes, even when the gateway is disabled.
* New: explicit returned-payment handling (Failed with history kept, orders-list badge, notice, email); a returned payment is never debited again (Plaid's reprocessing rules); safe repayment after a failed payment as a new attempt with full payment history.
* New: bank statement description setting; a Link customization is required in Sandbox and Production; the customer's legal name is required.
* New: Plaid event streams, cursors and refund identities are kept per Plaid account, so switching accounts can never skip or mix transfer events.
* Changed: requires WooCommerce 8.7 or later (earlier versions do not record HPOS refunds as refunded through the gateway).
* Changed: background reconciliation stops when no payment, refund or event needs monitoring anymore.
* New: protection against switching Plaid accounts or environments while payments are monitored.
* New: configuration status, classified connection test, richer diagnostics and order panel, manual-review decisions and "Cancel bank payment".
* Changed: the ACH class is always WEB; the ACH class and reconciliation settings were removed.
* Improved: accessibility of the payment page and admin status colors (WCAG 2.2 AA).
* New: Russian (ru_RU) translation.

= 0.1.0 =
* Initial release: Pay by Bank with Plaid Transfer UI, verified webhooks, transfer event sync, reconciliation, HPOS and Checkout block support, diagnostics, Site Health checks and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
Adds native refunds and long-term ACH return monitoring. Requires WooCommerce 8.7+. The database schema is upgraded automatically. Enter a Link customization name (Account Select: "Enabled for one account") in Sandbox and Production before accepting new payments.
