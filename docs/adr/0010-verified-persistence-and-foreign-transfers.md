# ADR-0010: Verified order persistence and classification of foreign transfers

**Status:** Accepted

## Context

Two failure modes were found while testing against real WooCommerce and Plaid Sandbox.

1. **Silent save failures.** `WC_Abstract_Order::save()` catches storage exceptions, logs
   them and returns normally. Code that writes the payment state and then calls
   `payment_complete()` could therefore complete an order whose PayBridge state was never
   stored, and a later replay would see an inconsistent order.
2. **Shared Plaid accounts.** `/transfer/event/sync` returns every transfer event of the
   Plaid client, not only PayBridge's. Other integrations, other stores (including a
   staging copy of the same store) and the other environment share that stream. Treating
   an unknown transfer as "not matched yet" would retry it forever; guessing an order from
   metadata would let another store's payment touch a local order with the same number.

## Decision

### Verified persistence

`OrderPersistence::save()` saves the order and then reads the critical keys back from the
data store (`read_meta` on the order data store, bypassing the in-memory object). If a
value differs, it throws `PersistenceException`; callers stop before any WooCommerce side
effect (status change, `payment_complete()`, emails, stock). The event stays retryable.

### Transfer ownership

- Every Transfer Intent carries metadata `pbfp_order_id`, `pbfp_attempt_id`,
  `pbfp_environment` and `pbfp_site`. `pbfp_site` is
  `substr(hash_hmac('sha256', 'paybridge-plaid-site:' . home_url('/'), wp_salt('auth')), 0, 16)`
  (`Support\SiteMarker`): stable for the store, different for a clone with another URL or
  salts, and not reversible to the URL.
- An event whose transfer is not in the payment index (ADR-0008) is resolved by
  `TransferEventProcessor::adopt()`, which reads `/transfer/get` and classifies it. Events
  are recorded as `ignored` with a reason code and never retried:

  | Code | Condition |
  |---|---|
  | `foreign_transfer` | no PayBridge metadata |
  | `foreign_site` | `pbfp_site` present and different from this store |
  | `environment_mismatch` | `pbfp_environment` differs from the configured environment |
  | `order_missing` | the referenced order does not exist or is not a PayBridge order |
  | `retired_attempt` | the attempt was retired in favour of a newer one |
  | `unknown_attempt` | the attempt ID is not the order's current attempt |
  | `transfer_unreadable` | Plaid answered `/transfer/get` with a definitive error |

- Only a transfer whose metadata matches the order's current attempt and whose intent
  (`/transfer/intent/get`, under the order mutex) is `SUCCEEDED` with the same
  `transfer_id` is bound to the order. Ambiguous errors (network, 5xx) leave the event
  retryable.

## Consequences

- An order is never completed on top of an unsaved payment state.
- Foreign activity on a shared Plaid account is visible in the event table with a precise
  reason, costs one `/transfer/get` call per transfer, and never changes local orders.
- Intents created before the store marker existed have no `pbfp_site`; they are still
  matched by attempt ID, which is random per attempt.
- Implementation: `src/Payment/OrderPersistence.php`, `src/Support/SiteMarker.php`,
  `src/Payment/TransferEventProcessor.php`; tests in
  `tests/Integration/wp-cli-payment-flow.php`.
