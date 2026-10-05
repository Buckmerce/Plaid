=== Buckmerce – Bank Payments via Plaid for WooCommerce ===
Contributors: al5dy
Tags: woocommerce, plaid, ach, pay by bank, bank transfer
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept secure Pay by Bank and ACH payments in WooCommerce with Plaid Transfer, native refunds, verified webhooks and return tracking.

== Description ==

**Turn WooCommerce into a complete Pay by Bank checkout with Plaid.**

Buckmerce adds a native WooCommerce payment gateway for customers who want to pay directly from a US bank account. Instead of treating bank payments like a manual offline transfer, Buckmerce connects checkout to **Plaid Transfer UI**, follows the payment through the ACH lifecycle, updates the order automatically, supports native WooCommerce refunds, and keeps monitoring for returns after the sale.

Customers get a clear **Pay by Bank** option at checkout, connect their bank in Plaid, choose the account to pay from, and authorize the payment. Your store never receives their bank login credentials.

* **WooCommerce ACH payments with Plaid Transfer** — Standard ACH or Same Day ACH.
* **Classic Checkout and Checkout Block support** — one gateway for both checkout experiences.
* **Server-authoritative payments** — Plaid, not the browser, determines payment state.
* **Verified Plaid webhooks** — ES256 JWT signature, key, token age and request-body hash verification.
* **Automatic ACH lifecycle tracking** — pending, posted, settled, funds available, failed, cancelled and returned.
* **Native WooCommerce refunds** — full and multiple partial refunds sent through Plaid and tracked to their final state.
* **ACH return monitoring** — returns are flagged in orders, notices and merchant email.
* **Background reconciliation** — missed webhooks and stale payments are recovered automatically.
* **Duplicate-payment protection** — protects against double clicks, retries and concurrent requests.
* **HPOS compatible** — built for WooCommerce High-Performance Order Storage.
* **Operational tools** — connection test, diagnostics, Site Health and redacted logs.
* **No Buckmerce license key** — no paid unlock, trial timer or usage quota.

Buckmerce is an independent third-party integration and is not developed, endorsed or supported by Plaid Inc., WooCommerce or Automattic.

= What you need =

* WordPress 6.6 or later.
* WooCommerce 8.7 or later.
* PHP 8.1–8.4 with OpenSSL.
* Store/order currency: USD.
* A Plaid account with **Plaid Transfer** enabled.
* HTTPS for Production payments.

== Installation ==

1. Install and activate **Buckmerce – Bank Payments via Plaid for WooCommerce**. WooCommerce must already be active.
2. Open **WooCommerce → Settings → Payments → Buckmerce for Plaid**.
3. In Plaid Dashboard **Developers → Keys**, enter the **Sandbox** Client ID and Secret.
4. Create and publish a **Link customization** in the same environment. Set **Account Select** to **Enabled for one account**, match the store language, and enter its name in Buckmerce.
5. If your Plaid Transfer account uses **Plaid Ledger**, leave **Funding Account ID** empty. Only enter a Funding Account ID for a Transfer account that does not use Ledger.
6. Choose the payment network: **Same Day ACH** or **Standard ACH**.
7. Choose when WooCommerce should mark the order paid: **Funds are available** (recommended) or **Transfer is settled**.
8. Copy Buckmerce's **Webhook URL** and add it in Plaid as a **Transfer event** webhook for the same environment.
9. Click **Test connection**. Resolve any failed item in the status checklist until Buckmerce reports **Ready**.
10. Enable **Offer Pay by Bank at checkout** and place a Sandbox order before switching to Production.

= Sandbox testing =

Sandbox moves no real money. Plaid provides test credentials inside Link. Useful order totals: **$11.11** succeeds, **$22.22** fails, and **$33.33** succeeds and is then returned (R01).

For live payments, obtain Plaid Transfer Production access, switch Buckmerce to **Production**, enter the Production Secret, create the Production Link customization and configure the Production webhook.

== Frequently Asked Questions ==

= What does the customer see? =

The customer selects **Pay by Bank** in WooCommerce, reviews the order amount on the Buckmerce payment page, then opens Plaid's secure bank connection. They choose their bank and account and authorize the payment in Plaid.

= Does Buckmerce accept credit or debit cards? =

No. Version 1.0 is focused on US bank-account payments through Plaid Transfer using ACH. It does not process Visa, Mastercard or other card payments.

= Does my store receive bank usernames, passwords or account numbers? =

No. Bank authentication and payment authorization happen inside Plaid. Buckmerce stores Plaid payment identifiers and payment state needed to operate the WooCommerce order.

= When is the WooCommerce order marked paid? =

By default, when Plaid reports **funds available**. You can instead choose **settled**. Until the configured confirmation state is reached, the order remains On hold.

= Can I issue refunds from WooCommerce? =

Yes. Use the normal WooCommerce refund interface and choose the automatic refund through Buckmerce. Full and multiple partial refunds are supported when the payment is eligible. Refund status is tracked through Plaid.

= What happens when an ACH payment is returned? =

Buckmerce records the return, marks the order Failed when appropriate, adds a private note, highlights it in the orders list, raises an alert and emails the merchant. If a refund was already sent, it raises a critical double-loss warning.

= Does it support Checkout Blocks and HPOS? =

Yes. Buckmerce supports Checkout Blocks and HPOS and uses WooCommerce CRUD APIs for order data.

= Does it support subscriptions? =

Not in version 1.0. Buckmerce 1.0 supports one-time Pay by Bank payments.

= Where do I troubleshoot a payment? =

Use the Buckmerce order panel, **WooCommerce → Buckmerce diagnostics**, Site Health, or WooCommerce logs with source `buckmerce-plaid`. Debug logs are redacted.

== External services ==

Buckmerce connects to **Plaid** to create and monitor Pay by Bank payments.

* **Plaid API** (`https://sandbox.plaid.com` or `https://production.plaid.com`) — receives server-side credentials and payment/refund data needed for Plaid Transfer.
* **Plaid Link** (`https://cdn.plaid.com/link/v2/stable/link-initialize.js`) — loaded on the customer payment page so the customer can connect a bank and authorize the payment.
* **Plaid webhooks** — send Transfer event notifications to the Buckmerce webhook endpoint.

Data sent to Plaid can include order amount and currency, statement description, billing name and email, refund amounts and internal order/payment references. Bank login credentials are handled by Plaid.

Plaid Terms: https://plaid.com/legal/
Plaid Privacy Policy: https://plaid.com/legal/#end-user-privacy-policy

== Privacy ==

Buckmerce collects no telemetry. Plaid identifiers and payment/refund states are stored for reconciliation, refunds and auditing. Suggested privacy text is added under **Settings → Privacy**.

== Security ==

* Plaid secrets stay server-side and are redacted from logs.
* Amount and currency come from the WooCommerce order, not the browser.
* Customer REST requests require order access plus a payment nonce.
* Plaid webhooks are authenticated before business processing.
* Administrator actions require capability checks and nonces.

== Source code ==

Compiled JavaScript and CSS are built from public TypeScript and SCSS source:

https://github.com/Buckmerce/Plaid

The plugin bundles `firebase/php-jwt` under the BSD-3-Clause license for Plaid webhook JWT verification.

== Screenshots ==

1. Buckmerce gateway settings with the Ready status checklist, Sandbox/Production badge, connection test and copyable webhook URL.
2. Pay by Bank available as a native payment method in WooCommerce checkout, including the Checkout Block.
3. The Buckmerce payment page showing the order total, Sandbox indicator and “Connect bank and pay” action before Plaid opens.
4. The WooCommerce order panel with payment attempt, Plaid Transfer Intent, transfer status, return windows, refunds and merchant actions.
5. Buckmerce diagnostics with environment, webhook status, background processing, event synchronization and a sanitized support report.

== Changelog ==

= 1.0.0 =
* Initial stable release of Pay by Bank through Plaid Transfer UI.
* Classic Checkout, Checkout Blocks and HPOS support.
* Standard ACH and Same Day ACH.
* Verified Plaid webhooks, durable event sync and background reconciliation.
* Native full and partial WooCommerce refunds with refund lifecycle tracking.
* ACH return monitoring, merchant alerts and returned-payment protection.
* Duplicate-payment protection, payment attempt history and Plaid account-change safeguards.
* Diagnostics, Site Health, redacted logs and WP-CLI operational commands.

== Upgrade Notice ==

= 1.0.0 =
Initial stable release. Configure Plaid Transfer credentials, Link customization and webhook before enabling live Pay by Bank payments.
