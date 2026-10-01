# ADR-0012: Returned bank payments keep WooCommerce "Failed" plus an explicit PayBridge marker

**Status:** Accepted (1.0.0); the customer-repayment part is superseded by ADR-0019 (no same-order re-debit after a return)

## Context

An ACH debit can be returned after it reached `funds_available` and after the order was
fulfilled. A return must never be overlooked and must not erase the payment history. Task 18
proposed a custom order status `wc-pbfp-returned` ("Bank payment returned") and asked to
evaluate it against WooCommerce practice.

Evaluation of a custom status (WooCommerce 8.5–11.1 source, `wc_get_order_statuses`,
Analytics settings, HPOS list table):

- **Revenue reporting:** WooCommerce Analytics counts every status that is not in the
  "Excluded statuses" setting (default: pending, failed, cancelled). A new status would be
  counted as revenue by default although the money was reversed — a financial misstatement.
- **Deactivation:** an unregistered status is hidden from the "All" orders list (legacy post
  status and HPOS status filter). Deactivating PayBridge would hide returned orders.
- **Interoperability:** fulfilment, ERP, accounting and email plugins do not know the status;
  `needs_payment()` and the order-pay flow would need extra filters.

`failed` has the needed semantics natively: not paid (`is_paid()` false, downloads blocked),
excluded from revenue by default, payable again through the order-pay link, and kept visible
without PayBridge.

## Decision

- A returned payment sets the WooCommerce status to **Failed** (unless the order is already
  Failed or Refunded) with a private note.
- The return is made explicit by PayBridge, not by a status:
  - payment state `returned`, return code, sanitized return reason, return timestamp;
  - a persistent admin alert and a merchant email (a stronger `returned_after_refund` alert
    when refunds were issued for the same transfer, ADR-0016);
  - a "Pay by Bank" column in the WooCommerce orders list (HPOS and legacy) showing
    "Bank payment returned (R01)";
  - a red notice in the order's PayBridge panel.
- History is never erased: the paid date, WooCommerce transaction ID, Plaid transfer ID,
  settlement/funds-available/return timestamps and all notes stay on the order. When the
  customer pays again, the returned attempt is archived (ADR-0017) and the new attempt gets
  its own transfer ID; the paid date then becomes the repayment's date because the order is
  paid by that attempt (the first paid date stays in the attempt history).
- Stock is not restocked automatically (goods may have shipped); the merchant decides.
  WooCommerce's own emails are not sent to the customer; the merchant contacts them.

## Consequences

- Correct revenue in WooCommerce Analytics and legacy reports without configuration.
- Orders stay visible and correct if PayBridge is deactivated.
- Merchants see the return in the list, the order, admin notices and email.
- Implementation: `OrderPaymentProjector::project_return()`, `Admin\OrderListColumn`,
  `Payment\PaymentAlerts`; tests: `tests/Integration/wp-cli-lifecycle.php`,
  `tests/Integration/wp-cli-refunds.php`, `tests/E2E/browser-smoke.js`.
