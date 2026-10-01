# ADR-0021: Background maintenance follows operational work; refund monitoring follows the refunded debit

**Status:** Accepted (1.0.0). Supersedes rule 1 of ADR-0014 (maintenance active when "a payment
epoch exists") and the "settled refunds daily for 10 days" rule of ADR-0016.

## Context

1. ADR-0014 kept the recurring reconciliation scheduled while PayBridge could read Plaid and
   (gateway enabled OR a payment epoch existed). The epoch exists forever after the first payment,
   so a disabled gateway scheduled reconciliation every 15 minutes forever — even years after every
   return window had closed.
2. Settled refunds were polled daily for 10 days. Plaid documents no return window for refunds:
   the refund object has no window fields (`/transfer/refund/get`, verified 2026-09-30) and the
   refund guide (<https://plaid.com/docs/transfer/refunds/>) gives none. 10 days was not derived
   from any provider or network rule.

## Decision

1. `Scheduler::maintenance_active()` = can reach Plaid AND (gateway enabled OR
   `Scheduler::pending_work(account scope)` has work): monitored payments (money in flight,
   pending authorizations, open return windows, manual review with a transfer), open refunds
   (creating, uncertain, pending, posted, or still monitored), stored events that are not final
   yet — waiting, or deferred for a retry whose backoff is still running (`TransferEventStore::
   has_backlog()`; they end as processed, ignored or abandoned after 20 attempts) — or a failed
   event sync (until a sync succeeds). Everything is an indexed query for the configured
   Plaid account. When no work remains, the recurring reconciliation is unscheduled.
   Disabling checkout still never abandons existing payments or refunds: they are work.
   Verified `TRANSFER_EVENTS_UPDATE` webhooks always queue an event sync, scheduled or not, so a
   late event (e.g. a return) is still recorded; the event sync follow-up handles backlogs.
2. `RefundMonitoringPolicy`: a settled refund is watched as long as the refunded debit is —
   until the debit's unauthorized return window (reported by Plaid) plus buffer closes, with the
   ADR-0014 fallback when Plaid reported none — and at least 14 days after the refund was created;
   daily while young, weekly afterwards. The horizon is derived only from fixed facts and is never
   shortened. While it lasts, reconciliation (and therefore event sync) keeps running.

## Consequences

- A store that stopped selling by bank eventually has no PayBridge background jobs at all.
- Refund returns are observed at least as long as any return of the payment itself, with bounded
  polling cost (weekly after two weeks).
- Tests: `tests/Integration/wp-cli-maintenance.php` (enabled/no payments, disabled with open
  payment, refund, backlog, deferred event, failed sync, truly idle, webhook while stopped),
  `tests/Unit/RefundContractTest.php` (refund horizon).
