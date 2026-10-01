# ADR-0014: Existing payments are maintained independently of the gateway, until their return windows close

**Status:** Accepted (1.0.0); rule 1 (maintenance while a payment epoch exists) superseded by ADR-0021. Supersedes the scheduling rules of 0.1.0 (reconciliation tied to
the enabled switch and a 60-day order-age lookback).

## Context

0.1.0 unscheduled reconciliation when the gateway was disabled or the "Reconciliation" option
was off, and stopped re-checking an order 60 days after the WooCommerce order was created.
Both abandoned real money: a disabled store still receives ACH returns, and an old order paid
again last week is in its return window. Plaid reports, per transfer
(`/transfer/get`): `standard_return_window` (3 business days after settlement: R01, R02, R03,
R29) and `unauthorized_return_window` (61 business days after settlement: R05, R07, R10, R11,
…), plus `expected_funds_available_date`.

## Decision

1. **Accepting new payments** (checkout, new Link sessions) requires the gateway to be enabled
   and completely configured. **Maintaining existing payments** (verified webhooks, event
   sync, reconciliation, return and refund monitoring, completion of an authorization that
   already happened) requires only credentials and a valid environment.
   `Scheduler::maintenance_active()`: can reach Plaid AND (enabled OR a payment epoch exists
   in this environment). The "Reconciliation" option was removed.
2. **Monitoring follows the transfer** (`Payment\MonitoringPolicy`):
   - unused intent: every 15 min while a Link token for it can be used (+ grace), then stop;
   - money in flight (created/pending/posted): hourly, 6-hourly after 7 days, never stops;
   - settled/funds available: every 6 h inside the standard window, daily until the
     unauthorized window closes (+2 days buffer), then per-order polling stops;
   - without provider dates: fallback 7 / 95 days from settlement (or transfer creation);
   - manual review with a transfer: daily until the return windows close;
   - terminal states: none.
   Event sync (cursor over all events) remains the primary channel for any later event.
3. The payment index row stores `payment_state`, `reconcile_after` and `monitor_until`
   (schema 2); `PaymentMonitor::refresh()` writes them after every authoritative change, so
   reconciliation, diagnostics and the account guard use indexed queries only.
4. Background errors are categorized (transient / permanent / local); event sync follow-ups
   back off exponentially (1 → 15 minutes); per-order failures retry after 15 minutes.

## Consequences

- Disabling the gateway can never hide a return or a refund outcome.
- Monitoring load is bounded by the return windows instead of order age.
- Diagnostics and Site Health report monitored payments, overdue jobs and WP-Cron status.
- Tests: `tests/Unit/MonitoringPolicyTest.php`, `tests/Integration/wp-cli-lifecycle.php`.
