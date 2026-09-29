=== PayBridge for Plaid — WooCommerce Pay by Bank ===
Contributors: al5dy
Tags: woocommerce, pay by bank, ach, bank transfer, plaid
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure Pay by Bank payments for WooCommerce using Plaid Transfer: customers pay directly from their US bank account.

== Description ==

PayBridge for Plaid adds a **Pay by Bank** payment method to WooCommerce. Customers connect their bank and authorize a one-time ACH debit in Plaid's Transfer UI. PayBridge creates the payment on your server, verifies every result with Plaid and keeps the WooCommerce order in step with the ACH lifecycle, including late returns.

PayBridge for Plaid is an independent third-party integration. It is not developed, endorsed or supported by Plaid Inc. You need your own Plaid account with Plaid Transfer enabled.

= Features =

* Pay by Bank for Classic Checkout and the Checkout block.
* Plaid Transfer UI: Plaid shows the payment details and captures the customer's authorization.
* Server-authoritative payments: the amount always comes from the WooCommerce order, and success is confirmed only by Plaid's API — never by the browser.
* ACH lifecycle tracking from Plaid transfer events: pending, posted, settled, funds available, failed, cancelled and returned.
* Conservative order handling: orders stay On hold until the configured confirmation point (funds available by default).
* ACH returns are impossible to miss: private order note, order marked Failed, admin notice and email to the site administrator.
* Webhooks are verified cryptographically (ES256 JWT and body hash) before anything happens.
* Background reconciliation recovers missed webhooks and stale payments.
* Duplicate-payment protection with database-backed payment reservations.
* Order panel with Plaid identifiers and a "Sync with Plaid" button.
* Diagnostics page, Site Health checks and redacted logs.
* HPOS (High-Performance Order Storage) compatible.

= Requirements =

* WordPress 6.6 or later, WooCommerce 8.5 or later, PHP 8.1 or later with OpenSSL.
* A Plaid account with Plaid Transfer enabled (Sandbox for testing, Production approval for live payments).
* Store currency USD.
* HTTPS for Production.

== Installation ==

1. Upload the plugin ZIP in **Plugins → Add New → Upload Plugin** and activate it. WooCommerce must be active.
2. Go to **WooCommerce → Settings → Payments → PayBridge for Plaid**.
3. Choose the **Environment** (start with Sandbox) and enter the **Client ID** and **Secret** from the Plaid Dashboard (Developers → Keys).
4. Leave **Funding Account ID** empty if your Plaid Transfer account uses Plaid Ledger (the default).
5. Copy the **Webhook URL** shown on the settings page. In the Plaid Dashboard open Team Settings → Webhooks, add a webhook for "Transfer event" and paste the URL.
6. Click **Test connection**, then enable the gateway.

= Plaid account =

PayBridge uses Plaid Transfer. Plaid must enable Transfer for your team; Production access requires Plaid's approval of your Transfer application. Optionally create a Link customization with Account Select set to "Enabled for one account" and enter its name in the settings.

= Sandbox =

In Sandbox no real money moves. Plaid simulates transfer outcomes by amount: an order total of $11.11 succeeds (pending → posted → settled → funds available), $22.22 fails, and $33.33 succeeds and is then returned (R01). Use the Plaid Sandbox test credentials shown in Plaid Link.

= Production =

Production moves real money. The site must use HTTPS; the gateway is hidden otherwise. Use the Production secret for the Production environment.

= Configuration =

* **Payment network** — Same Day ACH (default) or Standard ACH.
* **ACH class** — WEB (recommended for online consumer payments), PPD, CCD or TEL.
* **Mark order paid when** — Funds are available (recommended) or the transfer is settled. Until then the order is On hold.
* **Reconciliation** — background re-checks of open payments with Plaid (recommended).
* **Debug logging** — redacted logs under WooCommerce → Status → Logs (source `paybridge-for-plaid`).
* **Uninstall cleanup** — when enabled, deleting the plugin removes its settings, event history and database tables. Payment records on orders are always kept.

== Frequently Asked Questions ==

= Is this an official Plaid plugin? =

No. PayBridge for Plaid is an independent integration that uses the public Plaid API. It is not developed or endorsed by Plaid.

= When is an order marked as paid? =

When Plaid reports the transfer as funds available (or settled, if you choose that option). The customer finishing Plaid Link is not treated as proof of payment.

= What happens if a payment is returned? =

The order is marked Failed with a private note that includes the return code, an admin notice is shown until dismissed, and the site administrator receives an email. The original order history is preserved.

= Can a customer be charged twice? =

PayBridge keeps a single active Plaid Transfer Intent per order, protected by a database reservation, and Link tokens are issued only for that intent. Retries reuse it; a new one is created only after Plaid reports the previous attempt failed or it can no longer be authorized.

= Which currencies are supported? =

USD only.

= Does it support subscriptions or refunds? =

Not in this version. Refunds can be issued from the Plaid Dashboard.

== External services ==

This plugin connects to **Plaid** (Plaid Inc., https://plaid.com) to process Pay by Bank payments:

* **Plaid API** (`https://sandbox.plaid.com` or `https://production.plaid.com`) — called from your server to create and read Transfer Intents and transfers, create Link tokens, read transfer events and fetch webhook verification keys. Sent: your Plaid API credentials (server-to-server only), the order amount and currency, a short description ("Order 123"), the customer's billing name and email address, and internal order references. When: at checkout, when the customer pays, when Plaid sends a webhook, during background reconciliation and when an administrator syncs an order or tests the connection.
* **Plaid Link** (`https://cdn.plaid.com/link/v2/stable/link-initialize.js`) — loaded in the customer's browser on the payment page. The customer's bank connection and authorization happen inside Plaid's interface.
* **Plaid webhooks** — Plaid sends transfer notifications to your site's webhook URL.

Plaid terms: https://plaid.com/legal/ — Plaid end user privacy policy: https://plaid.com/legal/#end-user-privacy-policy

== Privacy ==

PayBridge sends only the data needed to create the payment (amount, description, billing name and email, order references) to Plaid. Bank login credentials and account numbers are handled by Plaid and are never received or stored by your store. Plaid payment identifiers and statuses are stored on the order for accounting. No telemetry is collected. Suggested privacy-policy text is added under Settings → Privacy.

== Security ==

* Plaid secrets are kept server-side, never rendered back in settings, never sent to the browser and redacted from logs.
* Webhooks are rejected unless the Plaid-Verification JWT (ES256), key ID, token age and request body hash all verify.
* Payment endpoints require the order key and the order owner's session; a numeric order ID alone never grants access.
* Report security issues privately to the plugin author.

== Screenshots ==

1. Gateway settings with webhook URL, configuration status and connection test.
2. The Pay by Bank payment page with the "Connect bank and pay" button.
3. The PayBridge panel on the WooCommerce order screen.
4. The PayBridge diagnostics page.

== Changelog ==

= 0.1.0 =
* Initial release: Pay by Bank with Plaid Transfer UI, verified webhooks, transfer event sync, reconciliation, HPOS and Checkout block support, diagnostics, Site Health checks and WP-CLI commands.
