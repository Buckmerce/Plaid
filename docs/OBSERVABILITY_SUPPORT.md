# Observability and Support

## 1. Goal

A support engineer diagnoses most payment and refund issues from WooCommerce admin, without
database access and without exposing secrets.

## 2. Structured logs

WooCommerce logger, source `paybridge-for-plaid`. `debug`/`info` only with **Debug logging**;
`warning`/`error` always. Context keys (redacted recursively by `Logging\Redactor`):
`order_id`, `attempt_id`, `transfer_intent_id`, `transfer_id`, `refund_id`, `wc_refund_id`,
`event_id`, `request_id` (Plaid), `environment`, `endpoint`, `http_status`, `duration_ms`,
`error_code` (Plaid code or a 16-hex fingerprint of an internal message), `category`
(transient/permanent/local). Request/response bodies are never logged.

## 3. Redaction

Keys containing: secret, password, access_token, public_token, link_token, processor_token,
authorization, cookie, plaid-verification, account_number, routing_number, legal_name,
email, phone, address, street, postal_code, ssn, jwt, signature, api_key, private_key — in any
case, with `-`/space/`_` variants, nested arrays and objects (`tests/Unit/RedactorTest.php`).

## 4. Configuration status (settings screen, diagnostics, Site Health)

`Admin\ConfigurationStatus`: **Ready**, **Disabled** (configured, new payments off),
**Incomplete** (a mandatory item fails) or **Attention required** (a warning), plus a
**Sandbox**/**Production** badge and a PASS/FAIL/WARN/INFO checklist: credentials, environment,
HTTPS, USD, Link customization (required in Sandbox and Production), last Link session error, schema,
webhook route, last connection test (classified), Action Scheduler, reconciliation scheduled,
overdue jobs and WP-Cron, last event-sync error, unmonitored payments (no credentials),
payments of another Plaid account, gateway enabled.

## 5. Order panel

Current attempt ID and creation, environment, amount, Transfer Intent ID and Plaid status,
transfer ID and Plaid status, transfer created / settled / funds available (or expected),
standard and unauthorized return windows (reported or estimated), returned time, return code,
failure code and reason, manual-review reason, last sync, last event ID, Plaid request ID,
refunded amount and refundable remainder, a refunds table (amount, status, Plaid refund ID,
WooCommerce refund, code, updated), earlier attempts, alerts for the order and — for a returned
payment — why it will not be debited again (per return code, ADR-0019). Actions:
**Sync with Plaid** (payment + refunds), **Cancel bank payment** (only while Plaid reports it
cancellable), manual-review decisions and **Let the customer pay again** (review without a
transfer, never after a return). No Plaid call happens on page view.

## 6. Orders list

"Pay by Bank" column: Bank payment returned (R01), Needs review, Bank payment failed/cancelled,
Awaiting ACH settlement, Settled, Funds available, Awaiting authorization.

## 7. Diagnostics screen (WooCommerce → PayBridge diagnostics, `wp paybridge-plaid status`)

Versions (plugin, PHP, WordPress, WooCommerce, database server), HPOS, Blocks, HTTPS, REST
route, Action Scheduler, `DISABLE_WP_CRON`, maintenance active, reconciliation scheduled,
overdue (> 30 min) and failed (7 days) PayBridge jobs, schema, gateway enabled, environment,
credentials present, account fingerprint, funding account, Link customization PASS/FAIL, last
Link session error, statement description, ACH class, last connection test, webhook URL, last
verified and last rejected webhook, the configured **event stream** (environment / account
fingerprint), its cursor (last stored event ID) and payment epoch, last event sync / error /
consecutive failures of that account, last
reconciliation / error, monitored payments and the oldest one, payments in flight, awaiting
authorization, in manual review, returned, intent creations with unknown outcome, payments of
another account, refunds pending / settled / failed-or-returned, event backlog, unmatched and
abandoned events of the configured account, and events of previous accounts or schema 2 (kept for
auditing only). A copyable support report contains the same values, never secrets or
customer data.

## 8. Site Health

"PayBridge for Plaid configuration" (critical when incomplete, recommended when attention is
needed) and "PayBridge for Plaid background processing" (critical when payments cannot be
monitored or Action Scheduler is missing; recommended for overdue jobs, WP-Cron without a real
cron, event-sync failures). Messages never include credentials.

## 9. Alerts and email

Persistent admin notices (dismissible, nonce-protected) and merchant emails
(`paybridge_plaid_alert_recipient` filter) for: ACH return, return after refund (critical),
manual review, refund failed, refund returned, refund unconfirmed, refund cancelled, refund
created outside WooCommerce.

## 10. Correlation

Plaid `request_id` is captured for every call (order meta, refund rows, logs) for Plaid support.
