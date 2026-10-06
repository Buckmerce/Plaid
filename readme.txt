=== Buckmerce – Bank Payments via Plaid for WooCommerce ===
Contributors: al5dy
Tags: woocommerce, plaid, ach, pay by bank, bank transfer
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept ACH bank payments in WooCommerce with Plaid. A Pay by Bank payment gateway with automatic order updates, refunds and ACH return alerts.

== Description ==

**Accept ACH bank payments in WooCommerce and let customers pay straight from their bank account with Plaid.**

Buckmerce adds a **Pay by Bank** payment method to your WooCommerce checkout. Customers choose their bank in a secure [Plaid](https://plaid.com/) window, pick the account to pay from and approve the payment. The money is collected from their US bank account by **ACH bank transfer**. No card is needed, and your store never sees their bank login details.

This is not a manual "direct bank transfer" method where you wait for money to arrive and then match it to an order by hand. Buckmerce follows every bank payment from checkout until the funds are available, updates the WooCommerce order for you, lets you refund from the normal order screen, and keeps watching for payments that a bank reverses later.

= Why add Pay by Bank to your store? =

* **One more way to pay at checkout** — offer ACH bank payments next to your other payment methods, for shoppers who prefer to pay from their bank account.
* **Less manual work** — orders move to On hold, Processing or Failed by themselves as the bank payment progresses. No more checking your bank statement to see who has paid.
* **Refunds where you already work** — send full or partial refunds back to the customer's bank from the standard WooCommerce refund screen.
* **No surprises after the sale** — a bank payment can be reversed days later. Buckmerce keeps monitoring and alerts you by email, in your WordPress admin and in the orders list.
* **Private by design** — customers sign in to their bank with Plaid. Bank usernames and passwords never touch your site.
* **Free and complete** — no license key, no paid upgrade to unlock features, no trial timer and no usage limits.

= How Pay by Bank works for your customers =

1. At checkout the customer selects **Pay by Bank** and places the order.
2. They review the order total and click **Connect bank and pay**.
3. A secure Plaid window opens. They choose their bank, sign in, select an account and confirm the amount.
4. They arrive on the order confirmation page, and your store updates the order when the bank payment completes.

If a customer closes the bank window or the bank declines the payment, no payment is made and they can simply try again.

= What happens in your store =

* **Payment submitted** — the order is placed On hold while the bank transfer is on its way.
* **Payment confirmed** — the order is marked paid (Processing or Completed, exactly as WooCommerce normally decides) once the funds are available. You can choose to confirm earlier, when the transfer is settled.
* **Payment failed** — if the payment fails before any money moves, the customer can pay for the same order again.
* **Payment returned** — if the customer's bank reverses the payment later, the order is set to Failed, a note is added, and you get an alert and an email. If that order was already refunded, Buckmerce sends an urgent warning and cancels pending refunds where Plaid still allows it.

= Features =

* **WooCommerce ACH payment gateway** with a choice of **Standard ACH** or **Same Day ACH**.
* **Checkout Block and Classic Checkout** support, for guests and logged-in customers.
* **Automatic order status updates** based on payment information confirmed by Plaid, never on what a browser reports.
* **Full and partial refunds** from the WooCommerce order screen, each one tracked until it reaches the customer's bank.
* **ACH return monitoring** that continues until the return period of each payment has passed.
* **Protection against double payments** caused by double clicks, page reloads or several open tabs.
* **Self-healing payment tracking** — if a notification from Plaid is missed, Buckmerce checks again on its own schedule.
* **Careful handling of unusual cases** — for example, a payment that arrives for an order you already cancelled is paused for your review instead of being processed automatically.
* **A clear order panel** with the payment status, key dates, refunds, earlier payment attempts and actions such as **Sync with Plaid** and **Cancel bank payment**.
* **A Pay by Bank column in the orders list**, so pending and returned bank payments are easy to spot.
* **Test mode** with Plaid Sandbox: run complete test orders without moving real money.
* **Guided setup** with a status checklist, a one-click connection test, a diagnostics page and WordPress Site Health checks.
* **Your own wording** for the payment method title, the checkout description and the short description shown on the customer's bank statement.
* **Compatible with WooCommerce High-Performance Order Storage (HPOS).**
* **Translation ready**, with a Russian translation included.

= Good to know =

Buckmerce for Plaid is built for **one-time payments in US dollars from US bank accounts**. It does not process credit or debit cards, subscriptions, saved bank accounts or other currencies. It works alongside your other WooCommerce payment methods, so you can keep accepting cards as usual.

= What you need =

* WordPress 6.6 or later and [WooCommerce](https://wordpress.org/plugins/woocommerce/) 8.7 or later.
* PHP 8.1–8.4 with OpenSSL.
* A store that sells in US dollars (USD).
* A [Plaid account](https://dashboard.plaid.com/signup) with [Plaid Transfer](https://plaid.com/products/transfer/) enabled.
* HTTPS on your site for live payments.

Buckmerce is an independent third-party integration and is not developed, endorsed or supported by Plaid Inc., WooCommerce or Automattic.

== Installation ==

1. In your WordPress admin, open **Plugins**, add a new plugin and search for **Buckmerce**. Install and activate **Buckmerce – Bank Payments via Plaid for WooCommerce**. WooCommerce must already be active.
2. Open **WooCommerce → Settings → Payments → Buckmerce for Plaid**.
3. Copy your **Client ID** and **Sandbox Secret** from the [Plaid Dashboard](https://dashboard.plaid.com/developers/keys) (**Developers → Keys**) into Buckmerce.
4. In the Plaid Dashboard, create and publish a [Link customization](https://dashboard.plaid.com/link) in the same environment. Set **Account Select** to **Enabled for one account**, use the same language as your store, and enter the customization name in Buckmerce.
5. If your Plaid Transfer account uses **Plaid Ledger**, leave **Funding Account ID** empty. Only enter a Funding Account ID for a Transfer account that does not use Ledger.
6. Choose the payment network: **Same Day ACH** or **Standard ACH**.
7. Choose when WooCommerce should mark the order paid: **Funds are available** (recommended) or **Transfer is settled**.
8. Copy the **Webhook URL** shown by Buckmerce and add it in the Plaid Dashboard under [Team Settings → Webhooks](https://dashboard.plaid.com/team/webhooks) as a **Transfer event** webhook for the same environment.
9. Click **Test connection**. Fix anything the status checklist flags until Buckmerce reports **Ready**.
10. Tick **Offer Pay by Bank at checkout** and place a test order in Sandbox before you switch to Production.

= Test mode (Plaid Sandbox) =

Sandbox moves no real money. When the bank window opens, sign in with the [test credentials provided by Plaid](https://plaid.com/docs/sandbox/test-credentials/). Useful order totals for the [Plaid Transfer Sandbox](https://plaid.com/docs/transfer/sandbox/): **$11.11** succeeds, **$22.22** fails, and **$33.33** succeeds and is then returned (R01).

= Going live =

[Apply to Plaid for Transfer Production access](https://plaid.com/docs/transfer/application/). Once you are approved, switch Buckmerce to **Production**, enter your Production Secret, create and publish the Link customization in Production and add the Production webhook. Your site must use HTTPS.

== Frequently Asked Questions ==

= What is Pay by Bank? =

Pay by Bank lets a customer pay for an order directly from a bank account instead of a card. In Buckmerce the customer connects their bank through Plaid and approves a one-time ACH payment, and WooCommerce is updated automatically as the payment moves through the banking system.

= How do I accept ACH payments in WooCommerce with this plugin? =

Install and activate Buckmerce, enter your Plaid keys, follow the status checklist until it shows **Ready**, and tick **Offer Pay by Bank at checkout**. The Installation section lists every step. You can try the whole flow in test mode before you take a real payment.

= Do I need a Plaid account? =

Yes. Payments are processed by Plaid, so you need your own [Plaid account](https://dashboard.plaid.com/signup) with [Plaid Transfer](https://plaid.com/products/transfer/) enabled. You can start in Plaid's free Sandbox and [apply for Production access](https://plaid.com/docs/transfer/application/) when you are ready to accept live payments.

= Is Buckmerce free? What does it cost? =

The plugin is free. There is no license key, no paid upgrade, no trial timer and no usage limit, and Buckmerce adds no fees of its own. Plaid charges for Plaid Transfer according to your agreement with Plaid, so check your Plaid pricing and compare it with your card processing fees.

= Which countries, currencies and banks are supported? =

Buckmerce for Plaid is for stores that charge in US dollars and customers who pay from a US bank account at a bank or credit union supported by Plaid. Pay by Bank is hidden automatically when the store or order currency is not USD.

= What does the customer see? =

The customer selects **Pay by Bank** in WooCommerce, reviews the order amount on the payment page, then opens Plaid's secure bank connection. They choose their bank and account and authorize the payment in Plaid, then return to the order confirmation page.

= Is this the same as an eCheck, direct debit or wire transfer? =

Pay by Bank in Buckmerce is an ACH debit: the customer authorizes a one-time payment that is collected from their US bank account. Some people call this kind of payment an eCheck or ACH direct debit. It is not a wire transfer and not a paper check.

= How long does a bank payment take? =

ACH is not instant. Bank payments usually take a few business days to complete. The exact timing depends on the payment network you choose (Standard ACH or Same Day ACH), on Plaid and on the banks involved. Same Day ACH payments made after Plaid's daily cut-off are sent as Standard ACH automatically.

= When is the WooCommerce order marked paid? =

By default, when Plaid reports that the funds are available. You can instead choose to confirm when the transfer is settled. Until then the order stays On hold. A bank payment can still be returned after it is marked paid, which is why Buckmerce keeps monitoring it.

= Does my store receive bank usernames, passwords or account numbers? =

No. Bank sign-in and payment authorization happen inside Plaid. Buckmerce only stores the Plaid payment references and payment status needed to manage the WooCommerce order.

= Can I issue refunds from WooCommerce? =

Yes. Use the normal WooCommerce refund screen and choose the automatic refund through Buckmerce. Full refunds and several partial refunds are supported. A payment can be refunded once it has settled, for up to 180 days, in up to 10 refunds that together do not exceed the amount paid. Each refund is tracked until it is completed, and you are alerted if a refund fails.

= What is an ACH return, and what happens when a payment is returned? =

An ACH return is a bank payment that is reversed by the customer's bank, for example because of insufficient funds, a closed account or because the customer told the bank the payment was not authorized. It can happen days or even weeks after the payment looked successful. Buckmerce records the return, sets the order to Failed, adds a private order note, highlights the order in the orders list, shows an alert in your admin and emails the store administrator. If a refund had already been sent, you get an urgent warning that both the payment and the refund may be lost.

= Can a customer try again after a failed payment? =

Yes. If the payment was declined or failed before any money moved, the customer can pay for the same order again. If a bank payment was returned after it went through, Buckmerce does not debit the bank account again for that order. The customer is asked to choose another payment method or to contact the store.

= Does Buckmerce accept credit or debit cards? =

No. Plugin is focused on US bank account payments through Plaid Transfer using ACH. It does not process Visa, Mastercard or other card payments.

= Can I use it together with other payment gateways? =

Yes. Pay by Bank is a regular WooCommerce payment method and appears next to the other payment methods you have enabled.

= Does it support Checkout Blocks, HPOS and guest checkout? =

Yes. Buckmerce works with the Checkout Block and Classic Checkout, is compatible with High-Performance Order Storage (HPOS), and can be used by guests as well as logged-in customers. The customer's first and last name in the billing details are required to pay by bank.

= Does it support subscriptions or saved bank accounts? =

Buckmerce for Plaid supports one-time Pay by Bank payments.

= Can I test it without real money? =

Yes. Choose the **Sandbox** environment to place test orders with Plaid's test bank. No real money moves in Sandbox, and the payment page clearly shows that the store is in test mode.

= What happens if I turn Pay by Bank off? =

Disabling the payment method only hides Pay by Bank at checkout. Existing bank payments, returns and refunds keep being monitored. Keep the plugin itself active while recent payments could still be returned, because deactivating the plugin stops that monitoring.

= Is it translation ready? =

Yes. All texts shown to customers and store managers can be translated, and a Russian translation is included.

= Can developers extend it? =

Yes. Buckmerce fires WordPress action hooks when a payment is confirmed, fails, is cancelled or is returned and when a refund changes, and it includes WP-CLI commands for day-to-day operations. See the [developer documentation on GitHub](https://github.com/Buckmerce/Plaid#readme).

= Where do I troubleshoot a payment? =

Open the order to see the Buckmerce panel with the payment status and history. For the store as a whole, use **WooCommerce → Buckmerce diagnostics**, Site Health, or the WooCommerce logs with source `buckmerce-plaid`. Sensitive details are removed from the logs.

= Where can I get help? =

Ask in the [plugin support forum](https://wordpress.org/support/plugin/buckmerce-plaid/). The diagnostics page includes a support report that is safe to share: it contains no credentials and no customer data.

== External services ==

Buckmerce relies on [Plaid](https://plaid.com/), a third-party financial service, to connect customers' banks and to create, track and refund Pay by Bank payments. The plugin cannot take payments without it.

* **Plaid API** (`https://sandbox.plaid.com` in test mode or `https://production.plaid.com` for live payments) — contacted by your server whenever a Pay by Bank payment or refund is created, checked or cancelled, during background status checks and when you test the connection. It receives your Plaid API credentials and the payment and refund details listed below.
* **Plaid Link** (`https://cdn.plaid.com/link/v2/stable/link-initialize.js`) — loaded in the customer's browser on the payment page of an order that is being paid with Pay by Bank, so the customer can connect a bank and approve the payment.
* **Plaid webhooks** — Plaid notifies your site when the status of a payment or refund changes.

Information sent to Plaid can include the order amount and currency, the bank statement description, the customer's billing name and email address, refund amounts and internal order and payment references. Bank login details are entered directly with Plaid and are never sent to your store.

Plaid provides this service under its own terms: [Plaid legal terms](https://plaid.com/legal/) and [Plaid End User Privacy Policy](https://plaid.com/legal/#end-user-privacy-policy).

== Privacy ==

Buckmerce does not track you or your customers and sends no usage data to its author. Plaid payment references and payment and refund statuses are stored with your orders and in the plugin's own records, so that payments can be followed, refunded and audited. Suggested text for your privacy policy is added under **Settings → Privacy**.

When you delete the plugin, its settings and history are removed only if you turned on **Uninstall cleanup**. The payment details saved on your orders always stay.

== Security ==

* Your Plaid Secret stays on your server. It is never shown again in the settings, never sent to the browser and is removed from logs.
* The amount and currency always come from the WooCommerce order, so they cannot be changed in the customer's browser.
* Only the customer who placed an order can open its payment page.
* Every notification from Plaid is checked to be genuine before it can change an order.
* Payment actions in the admin are limited to users who are allowed to manage WooCommerce.

== Source code ==

The JavaScript and CSS shipped with the plugin are built from readable TypeScript and SCSS source code. The full source and the build tools are public on GitHub: [github.com/Buckmerce/Plaid](https://github.com/Buckmerce/Plaid).

The plugin bundles the `firebase/php-jwt` library (BSD-3-Clause license), which is used to verify that notifications from Plaid are genuine.

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
