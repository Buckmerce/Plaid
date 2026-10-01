# ADR-0016: Native refunds as a separate, idempotent refund domain

**Status:** Accepted (1.0.0); refund identity scoped by account (ADR-0018) and settled-refund monitoring superseded by ADR-0021

## Context

WooCommerce refunds call `WC_Payment_Gateway::process_refund($order_id, $amount, $reason)`
after saving a `WC_Order_Refund`; on an error it deletes that object again. Plaid
(`/transfer/refund/create|get|cancel`, verified 2026-09-30): an `idempotency_key` (≤ 50) per
refund, at most 10 refunds per transfer, total ≤ transfer amount, within 180 days, never for
cancelled/failed/returned transfers, funded from the Ledger's available balance. Refund
statuses: pending, posted, settled, failed, cancelled, returned; refund events are
`refund.*` with the ORIGINAL `transfer_id` and a non-null `refund_id`. A debit refunded and
later returned can cost the merchant twice.

## Decision

- **Separate domain** (`src/Refund/`): `RefundState` + `RefundStateMachine` (never the payment
  state machine), `RefundPolicy` (local-data eligibility and exact cent arithmetic),
  `RefundService`, `RefundEventHandler`, `RefundMonitoringPolicy`; persistence in
  `{prefix}paybridge_plaid_refunds` (`Persistence\RefundStore`) with unique `idempotency_key`,
  unique `(environment, refund_id)` and unique `wc_refund_id`.
- **Eligibility:** the current transfer is `settled` or `funds_available` (refunding before
  settlement is refused: cancel instead), same environment and Plaid account, ≤ 180 days,
  < 10 counted refunds, no unconfirmed refund, amount ≤ transfer amount − Σ active refunds
  (creating, uncertain, pending, posted, settled; failed/cancelled/returned/rejected/void do not
  count). Refunds created outside WooCommerce are recorded and count too.
- **Idempotent creation:** under a per-order database mutex, re-check eligibility and a
  one-minute identical-amount double-submit guard, reserve the row (`creating`, owner token,
  lease) BEFORE calling Plaid; key = `pbfp-` + 44 hex of sha256(site, environment, order,
  WooCommerce refund ID, attempt, amount). The WooCommerce refund object is captured from
  `woocommerce_create_refund` (fallback: the single matching unpaid refund of the last 10
  minutes). A retried `process_refund` for the same WooCommerce refund returns the recorded
  outcome.
- **Ambiguity:** a timeout/5xx is retried once with the same key; if still unknown, Plaid's
  `transfer.refunds[]` is checked for an unrecorded refund of that amount; otherwise the row
  becomes `uncertain`, WooCommerce gets an error ("do not refund again"), further refunds of the
  order are blocked, and reconciliation later **adopts** the refund (restoring the WooCommerce
  refund record) or marks it `void` after 30 minutes. The create is never re-sent autonomously.
  A definitive rejection is `rejected` with Plaid's code and explanation.
- **Lifecycle:** verified webhook → `/transfer/event/sync` → `RefundEventHandler` (by
  `refund_id`; adopts unlinked reservations; records external refunds of this store's
  transfers) → refund state machine; reconciliation re-reads due refunds with
  `/transfer/refund/get` (in flight every 6 h; settled refunds daily for 10 days).
- **Outcomes:** failed/returned refunds raise alerts and emails and mark the WooCommerce refund
  (`_pbfp_refund_status`); the WooCommerce refund record is not deleted automatically — the
  merchant is told to delete it and refund again.
- **Return after refund:** when the original debit is returned, still-pending refunds are
  cancelled at Plaid (the customer already has the money back) and a critical
  `returned_after_refund` alert, note and email state the amount that already left.

## Consequences

- One WooCommerce refund can never produce two Plaid refunds; refunds never exceed the
  refundable amount; unknown outcomes are visible and resolved from Plaid.
- Tests: `tests/Unit/RefundPolicyTest.php`, `RefundContractTest.php`,
  `StateOrderingMatrixTest.php`, `tests/Integration/wp-cli-refunds.php`,
  `concurrency.php` (parallel refunds), `tests/E2E/browser-smoke.js` (admin UI),
  `tests/E2E/sandbox-refunds.php` (real Plaid Sandbox).
