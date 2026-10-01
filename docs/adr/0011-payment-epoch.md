# ADR-0011: Payment epoch for the shared Plaid event history

**Status:** Accepted; scope superseded by ADR-0018 (the epoch is per environment AND Plaid account)

## Context

`/transfer/event/sync` starts at `after_id = 0` on a fresh installation and returns every
transfer event of the Plaid client: other stores, other integrations, a staging copy, years
of history. ADR-0010 classifies a transfer that is not in the payment index by reading
`/transfer/get` for its metadata. On an account with a long history the first
synchronisation therefore made one `/transfer/get` call per historical transfer, spread over
many 40-second runs, and new PayBridge events (which come last in `event_id` order) waited
behind that backlog. The real Sandbox gate showed the effect growing run after run on a shared
account.

## Decision

- `Persistence\PaymentEpoch` stores, per environment, the time this store created its first
  Transfer Intent (`paybridge_plaid_first_intent_at_{environment}`). It is written with
  `add_option()` (never overwritten) inside the fail-closed section before the first remote
  `/transfer/intent/create`; if it cannot be stored, no intent is created.
- `TransferEventProcessor::adopt()` ignores an unindexed event with code
  `before_first_payment`, without any API call, when no epoch exists or the event timestamp
  is more than one hour (clock tolerance) older than the epoch.
- Events newer than the epoch keep the ADR-0010 classification; events without a parsable
  timestamp are classified normally.

## Consequences

- The first synchronisation of a new store costs one `/transfer/event/sync` page per 100
  historical events and no `/transfer/get` calls for them; new payments are not delayed.
- A PayBridge transfer can never be skipped: its intent, and therefore every event of it, is
  created after the epoch.
- If an administrator deletes PayBridge data (opt-in uninstall cleanup) and reinstalls, events
  of transfers created before the new epoch are ignored. Those orders keep their recorded
  state, notes and Plaid identifiers for auditing; their payment index rows were deleted
  with the cleanup, so they are no longer synchronised.
- Covered by `tests/Unit/PaymentEpochTest.php`, the payment-flow integration suite and the
  catch-up step of the real Sandbox gate.
