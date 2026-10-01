# ADR-0008: Plugin-owned payment index instead of order meta queries

**Status:** Accepted

## Context

Plaid events and webhooks identify payments by `transfer_id`, and reconciliation must find
orders whose intent or transfer is still open. The obvious implementation is
`wc_get_orders(array('meta_query' => ...))` on `_pbfp_transfer_id` / `_pbfp_transfer_intent_id`.

That query is only reliable with HPOS. With legacy (posts) storage WooCommerce ignores
`meta_query` in `wc_get_orders()` and logs a notice, so the lookup silently returns
unrelated orders. PayBridge supports both storage modes (ADR-0006), and a wrong match
here would apply another order's money movement to the wrong order.

The durable reservation table from ADR-0003 already holds one row per PayBridge order.

## Decision

- `{prefix}paybridge_plaid_payment_locks` is also the payment index. It stores
  `transfer_intent_id`, `transfer_id` and `reconcile_after`, each indexed.
- `PaymentLockStore::find_by_transfer_id()` / `find_by_intent_id()` return a match only when
  exactly one row matches; two rows are a correlation conflict and are never guessed.
- `bind_transfer()` records the transfer for the active intent with a compare-and-set that
  refuses to replace a different transfer or bind to another intent.
- Reconciliation selects due orders with `due_for_reconciliation()` (ordered by
  `reconcile_after`) instead of an order meta query.
- Order meta remains the customer-facing and auditing record; the index is written first
  and the meta written afterwards is verified (see ADR-0010).

## Consequences

- Lookups and reconciliation behave identically with HPOS on and off; the integration
  suite runs every flow in both modes.
- The index is plugin-owned, so uninstall cleanup can remove it without touching
  WooCommerce tables. Order meta keeps the IDs if the merchant keeps order data.
- For an active match the order meta must agree with the index (defense in depth); a
  disagreement is treated as no match.
- A row marked `retired` still resolves its identifiers as `MATCH_RETIRED`, so late events
  for that attempt are ignored. The table keeps one row per order: once a new attempt claims
  the row, events for older attempts are recognised through the attempt ID in the Plaid
  transfer metadata (`TransferEventProcessor::adopt()`, see ADR-0010).
- Implementation: `src/Persistence/PaymentLockStore.php`, `src/Payment/OrderLocator.php`,
  `src/Background/ReconciliationService.php`.
