=== PayBridge for Plaid — WooCommerce Pay by Bank ===
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

PayBridge for Plaid adds a **Pay by Bank** payment method to WooCommerce. Customers connect their bank and authorize a one-time ACH debit in Plaid's Transfer UI. PayBridge creates the payment on your server, verifies every result with Plaid, keeps the WooCommerce order in step with the ACH lifecycle until the ACH return window closes, and refunds payments back to the customer's bank.

PayBridge for Plaid is an independent third-party integration. It is not developed, endorsed or supported by Plaid Inc. You need your own Plaid account with Plaid Transfer enabled.

= Features =

* Pay by Bank for Classic Checkout and the Checkout block.
* Plaid Transfer UI: Plaid shows the payment details and captures the customer's authorization (Nacha WEB).
* Server-authoritative payments: the amount always comes from the WooCommerce order, and success is confirmed only by Plaid's API — never by the browser.
* ACH lifecycle tracking from Plaid transfer events: pending, posted, settled, funds available, failed, cancelled and returned.
* Monitoring until Plaid's unauthorized return window closes (about three months), even if you disable the gateway.
* Native WooCommerce refunds through Plaid: full and multiple partial refunds, protected against duplicates, with refund status tracking.
* ACH returns are impossible to miss: private order note, order marked Failed with its payment history kept, "Bank payment returned" badge in the orders list, admin notice and email — a critical alert if the payment was already refunded.
* Customers can pay a returned or failed order again; every attempt stays in the order's payment history.
* Webhooks are verified cryptographically (ES256 JWT and body hash) before anything happens.
* Background reconciliation recovers missed webhooks, stale payments and unconfirmed refunds.
* Duplicate-payment protection with database-backed payment reservations.
* Protection against switching Plaid accounts while payments are still monitored.
* Order panel with Plaid identifiers, return windows, refunds, "Sync with Plaid" and "Cancel bank payment" while Plaid allows it.
* Configuration status, diagnostics page, Site Health checks and redacted logs.
* HPOS (High-Performance Order Storage) compatible.

= Requirements =

* WordPress 6.6 or later, WooCommerce 8.5 or later, PHP 8.1 – 8.4 with OpenSSL, MySQL 8.0+ or MariaDB 10.11+.
* A Plaid account with Plaid Transfer enabled (Sandbox for testing, Production approval for live payments).
* Store currency USD.
* HTTPS for Production.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin** and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → Payments → PayBridge for Plaid**.
3. Choose the **Environment** (start with Sandbox) and enter the **Client ID** and **Secret** from the Plaid Dashboard (Developers → Keys).
4. Leave **Funding Account ID** empty if your Plaid Transfer account uses Plaid Ledger (the default).
5. For Production, create a Plaid Link customization with Account Select set to "Enabled for one account" and enter its name.
6. Copy the **Webhook URL** shown on the settings page. In the Plaid Dashboard open Team Settings → Webhooks, add a webhook for "Transfer event" and paste the URL.
7. Click **Test connection**; when the status panel shows Ready, enable the gateway.

= Plaid account =

PayBridge uses Plaid Transfer. Plaid must enable Transfer for your team; Production access requires Plaid's approval of your Transfer application. Production requires a Link customization with Account Select set to "Enabled for one account" (its language must match your store language). Refunds are paid from your Plaid Ledger's available balance.

= Sandbox =

In Sandbox no real money moves. Plaid simulates transfer outcomes by amount: an order total of $11.11 succeeds (pending → posted → settled → funds available), $22.22 fails, and $33.33 succeeds and is then returned (R01). Refunds of $1.11 are returned and $2.22 fail. Use the Plaid Sandbox test credentials shown in Plaid Link. The payment page shows a small "Sandbox" badge.

= Production =

Production moves real money. The site must use HTTPS; the gateway is hidden otherwise. Use the Production secret for the Production environment.

= Configuration =

* **Offer Pay by Bank at checkout** — controls new payments only; existing payments, returns and refunds are always monitored.
* **Link customization name** — required in Production (see above).
* **Bank statement description** — shown on the customer's bank statement after your company name (default PAYMENT, up to 10 letters/digits/spaces).
* **Payment network** — Same Day ACH (default) or Standard ACH.
* **Mark order paid when** — Funds are available (recommended) or the transfer is settled. Until then the order is On hold.
* **Debug logging** — redacted logs under WooCommerce → Status → Logs (source `paybridge-for-plaid`).
* **Uninstall cleanup** — when enabled, deleting the plugin removes its settings, event and refund history and database tables. Payment records on orders are always kept.

The ACH class is always WEB (internet-authorized consumer debit), as required for Plaid Transfer UI. Customers must provide a billing first and last name (the bank account holder's legal name).

== Frequently Asked Questions ==

= Is this an official Plaid plugin? =

No. PayBridge for Plaid is an independent integration that uses the public Plaid API. It is not developed or endorsed by Plaid.

= When is an order marked as paid? =

When Plaid reports the transfer as funds available (or settled, if you choose that option). The customer finishing Plaid Link is not treated as proof of payment.

= What happens if a payment is returned? =

The order is marked Failed with a private note that includes the return code and reason, the orders list shows "Bank payment returned", an admin notice is shown until dismissed, and the site administrator receives an email. The original paid date, transaction ID and history are preserved, and the customer can pay the order again (a new payment attempt). If you had already refunded the payment, PayBridge cancels still-pending refunds and raises a critical alert, because you may lose both the payment and the refund.

= Can a customer be charged twice? =

PayBridge keeps a single active Plaid Transfer Intent per order, protected by a database reservation, and Link tokens are issued only for that intent. Retries reuse it; a new one is created only after Plaid reports the previous attempt failed or it can no longer be authorized.

= Which currencies are supported? =

USD only.

= Can I refund a Pay by Bank payment? =

Yes. Use WooCommerce's Refund button on the order and choose the automatic refund through PayBridge for Plaid. Full and multiple partial refunds are supported once the payment has settled (Plaid allows up to 10 refunds per payment within 180 days). PayBridge prevents duplicate refunds and tracks each refund until it settles, fails or is returned.

= What happens if I disable the gateway or change Plaid keys? =

Disabling only hides Pay by Bank at checkout; existing payments keep being monitored. You can rotate your Plaid secret at any time, but PayBridge refuses to switch to another Plaid Client ID or environment while Production payments are still monitored, because the old payments could no longer be checked for returns.

= Does it support subscriptions? =

No. PayBridge 1.0 supports one-time payments.

== External services ==

This plugin connects to **Plaid** (Plaid Inc., https://plaid.com) to process Pay by Bank payments:

* **Plaid API** (`https://sandbox.plaid.com` or `https://production.plaid.com`) — called from your server to create and read Transfer Intents and transfers, create Link tokens, create, read and cancel refunds, cancel a transfer on your request, read transfer events and fetch webhook verification keys. Sent: your Plaid API credentials (server-to-server only), the order amount and currency, your bank statement description, the customer's billing name and email address, refund amounts and internal order references. When: at checkout, when the customer pays, when you refund or cancel, when Plaid sends a webhook, during background reconciliation and when an administrator syncs an order or tests the connection.
* **Plaid Link** (`https://cdn.plaid.com/link/v2/stable/link-initialize.js`) — loaded in the customer's browser on the payment page. The customer's bank connection and authorization happen inside Plaid's interface.
* **Plaid webhooks** — Plaid sends transfer notifications to your site's webhook URL.

Plaid terms: https://plaid.com/legal/ — Plaid end user privacy policy: https://plaid.com/legal/#end-user-privacy-policy

== Privacy ==

PayBridge sends only the data needed to create the payment or refund (amount, statement description, billing name and email, order references) to Plaid. Bank login credentials and account numbers are handled by Plaid and are never received or stored by your store. Plaid payment identifiers and statuses are stored on the order for accounting. No telemetry is collected. Suggested privacy-policy text is added under Settings → Privacy.

== Security ==

* Plaid secrets are kept server-side, never rendered back in settings, never sent to the browser and redacted from logs.
* Webhooks are rejected unless the Plaid-Verification JWT (ES256), key ID, token age and request body hash all verify.
* Payment endpoints require the order key and the order owner's session; a numeric order ID alone never grants access.
* Every administrator action (sync, cancel, review decisions, alerts) requires the WooCommerce management capability and a nonce.
* Report security issues privately to the plugin author.

== Screenshots ==

1. Gateway settings with the status panel, webhook URL and connection test.
2. The Pay by Bank payment page with the "Connect bank and pay" button.
3. The PayBridge panel on the WooCommerce order screen, with refunds and payment history.
4. The PayBridge diagnostics page.

== Changelog ==

= 1.0.0 =
* New: native WooCommerce refunds through Plaid (full and multiple partial), protected against duplicates, with refund status tracking, reconciliation and alerts.
* New: payments are monitored until Plaid's unauthorized return window closes, even when the gateway is disabled.
* New: explicit returned-payment handling (Failed with history kept, orders-list badge, notice, email) and safe repayment as a new attempt with full payment history.
* New: bank statement description setting; Production requires a Link customization; the customer's legal name is required.
* New: protection against switching Plaid accounts or environments while payments are monitored.
* New: configuration status, classified connection test, richer diagnostics and order panel, manual-review decisions and "Cancel bank payment".
* Changed: the ACH class is always WEB; the ACH class and reconciliation settings were removed.
* Improved: accessibility of the payment page and admin status colors (WCAG 2.2 AA).

= 0.1.0 =
* Initial release: Pay by Bank with Plaid Transfer UI, verified webhooks, transfer event sync, reconciliation, HPOS and Checkout block support, diagnostics, Site Health checks and WP-CLI commands.

== Upgrade Notice ==

= 1.0.0 =
Adds native refunds and long-term ACH return monitoring. The database schema is upgraded automatically. In Production, enter a Link customization name (Account Select: "Enabled for one account") before accepting new payments.
