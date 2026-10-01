# ADR-0017: Payment attempt history and repayment

**Status:** Accepted (1.0.0); repayment after a RETURN superseded by ADR-0019 (repayment after failures before money moved is unchanged). Extends ADR-0008 (one payment-index row per order).

## Context

An order can need several payment attempts: a declined authorization, an unknown intent
creation, a failed transfer, a return followed by repayment, a released manual review. The
payment index (ADR-0008) holds only the current attempt. 0.1.0 kept the last 20 retired
attempts as a thin audit list on the order and dropped older ones, even if they moved money.

## Decision

- The **current attempt** stays in the order's `_pbfp_*` meta and the index row. Before a new
  attempt replaces it, `Payment\AttemptHistory::archive()` stores the complete attempt in
  `_pbfp_retired_attempts`: attempt ID, environment, account fingerprint, amount, currency,
  created time, intent ID, transfer ID, final PayBridge state, Plaid transfer status,
  failure/return code and description, transfer-created/settled/funds-available/returned times,
  paid time, unauthorized return window, retirement reason and time.
- Attempts that created a transfer are **never dropped**; attempts without a transfer are
  capped at 20 (oldest dropped first) to keep the order bounded against retry abuse.
- A **repayment is always a new attempt**: new snapshot and attempt ID, new Transfer Intent,
  new Link tokens, new transfer; the retired attempt's identifiers are never reused. Late events
  of a retired attempt are ignored for the payment (`retired_attempt`); its refunds are still
  tracked by `refund_id` (ADR-0016).
- The index row is reset for the new attempt (identifiers, monitoring columns) under the
  existing compare-and-set reservation.

## Consequences

- `Order #123: attempt A → returned, attempt B → funds_available` is fully auditable in the
  order panel ("Earlier payment attempts") without a separate ledger table.
- Existing 0.1.0 entries remain readable (the attempt data is derived from their snapshot).
- Tests: `tests/Unit/OperationalHelpersTest.php` (retention), `tests/Integration/wp-cli-lifecycle.php`
  (return → repayment), `tests/E2E/browser-smoke.js` (repayment through the pay link).
