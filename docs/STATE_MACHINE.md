# Payment and Refund State Machines

## 1. Purpose

The state machines prevent contradictory, out-of-order or replayed provider signals from
corrupting WooCommerce orders. All payment-state changes pass through `PaymentStateMachine`
(ADR-0005); all refund-state changes pass through `RefundStateMachine` (ADR-0016). Refunds
never change the payment state.

Decisions: **APPLY** (allowed transition), **NOOP** (same state again — duplicate evidence),
**STALE** (older/weaker evidence after newer state — ignored, never regresses), **CONFLICT**
(contradictory evidence — logged, ignored, never applied).

## 2. Payment states (`src/Payment/PaymentState.php`)

```text
new (no meta)
intent_creating    /transfer/intent/create may be in flight
intent_created     intent stored; no Link token yet
intent_pending     a Link token was issued; the customer may be authorizing
intent_failed      Plaid reported the intent FAILED or the create was definitively rejected
intent_uncertain   the create had an unknown outcome (timeout/5xx); never tokenized
transfer_created   Plaid created the transfer from the SUCCEEDED intent
pending, posted, settled, funds_available      Plaid transfer lifecycle
failed, cancelled, returned                     terminal provider outcomes
manual_review      quarantine for contradictions (see §6)
```

Allowed transitions:

```text
new               → intent_creating
intent_creating   → intent_created | intent_failed | intent_uncertain | manual_review
intent_failed     → intent_creating | manual_review
intent_uncertain  → intent_creating | intent_created | manual_review
intent_created    → intent_pending | transfer_created | intent_failed | manual_review
intent_pending    → transfer_created | intent_failed | manual_review
transfer_created  → pending | posted | settled | funds_available | failed | cancelled | returned | manual_review
pending           → posted | settled | funds_available | failed | cancelled | returned | manual_review
posted            → settled | funds_available | failed | returned | manual_review
settled           → funds_available | returned | manual_review
funds_available   → returned
manual_review     → failed | cancelled | returned
failed, cancelled, returned → (none)
```

A new attempt after `failed`, `cancelled`, `intent_failed`, `intent_uncertain` or a released
review archives the current attempt (ADR-0017) and starts again at `new`. `returned` is final for
the order: no new bank debit is ever created after it (`ReturnRetryPolicy`, ADR-0019).

## 3. Payment ordering matrix (automated in `tests/Unit/StateOrderingMatrixTest.php`)

| From → To | Decision |
|---|---|
| pending → posted | APPLY |
| posted → pending | STALE |
| settled → posted | STALE |
| funds_available → settled | STALE |
| settled → funds_available | APPLY |
| funds_available → returned | APPLY |
| posted → returned, settled → returned | APPLY |
| failed → pending, returned → posted, returned → funds_available, cancelled → settled | STALE |
| manual_review → funds_available | STALE (never fulfils automatically) |
| manual_review → returned | APPLY |
| returned → returned, pending → pending | NOOP |
| returned → failed, failed → returned, funds_available → failed, posted → cancelled | CONFLICT |
| unknown status, → new | CONFLICT |

Every documented edge is asserted to APPLY and every undocumented edge not to APPLY
(`tests/Unit/PaymentStateMachineTest.php`).

## 4. WooCommerce projection (`OrderPaymentProjector`)

| PayBridge state | WooCommerce |
|---|---|
| intent_* | pending (unchanged) |
| transfer_created, pending, posted | on-hold |
| settled | on-hold, or `payment_complete()` when "Mark order paid when" = settled |
| funds_available | `payment_complete()` (WooCommerce decides processing/completed, stock, emails) |
| failed | failed (unless already paid/cancelled/refunded) |
| cancelled | cancelled (unless already paid/failed/refunded) |
| returned | failed (unless failed/refunded); history kept; alert + email (ADR-0012) |
| manual_review | on-hold; alert + email |

Money for a WooCommerce-cancelled order is never fulfilled: a success-lifecycle state is
turned into `manual_review` (`payment_for_cancelled_order`).

## 5. Refund states (`src/Refund/RefundState.php`)

```text
creating   reserved; /transfer/refund/create may be in flight (owner token + lease)
uncertain  create outcome unknown; resolved from Plaid only (adopt or void), never re-sent
rejected   Plaid definitively refused the create (no refund exists)
void       an uncertain create was proven not to have created a refund
pending, posted, settled   Plaid refund lifecycle
failed, cancelled, returned   terminal refund outcomes
```

Allowed transitions:

```text
creating  → pending | posted | settled | failed | cancelled | returned | uncertain | rejected
uncertain → pending | posted | settled | failed | cancelled | returned | void
pending   → posted | settled | failed | cancelled | returned
posted    → settled | failed | returned
settled   → returned
rejected, void, failed, cancelled, returned → (none)
```

Refund ordering (automated): posted → pending STALE; settled → posted STALE; settled → returned
APPLY; failed/returned/cancelled/rejected/void → any progress STALE; returned → failed,
settled → failed, posted → cancelled CONFLICT; → creating CONFLICT.

Active refunds (reduce the refundable amount): creating, uncertain, pending, posted, settled.

## 6. Manual review workflow

**Enters manual review** only on contradictions PayBridge must not resolve itself:
intent or transfer amount/currency/attempt mismatch (`intent_amount_mismatch`,
`transfer_amount_mismatch`, `intent_mismatch`), a transfer ID conflict, a reservation whose
snapshot does not match (`reservation_snapshot_mismatch`), a missing snapshot, an orphaned
reservation, or money arriving for a WooCommerce-cancelled order. Unhandled exceptions never
put a payment into manual review; they are retried or logged.

**The merchant sees** the order On hold, the reason in the PayBridge panel, a persistent
admin notice and an email.

**Stops:** automatic fulfilment (success signals are STALE), new payment attempts for the
order, Link sessions. **Continues:** event sync and reconciliation read the provider state;
`failed`, `cancelled` and `returned` still apply, so a later failure or return is recorded.

**Safe manual actions:** Sync with Plaid; cancel a still-cancellable transfer; refund through
WooCommerce when the transfer settled; record a decision ("fulfil manually", "refunded",
"customer contacted"), which is audited in `_pbfp_manual_review_resolution` and clears the
alert without changing the payment state; "Let the customer pay again" for a review without a
transfer, allowed only when no Link token can still be used and Plaid confirms the intent did
not create a transfer (the attempt is archived and the order returns to Pending).

**Clears automatically** when Plaid reports `failed`, `cancelled` or `returned`.

## 7. Tests

- `tests/Unit/PaymentStateMachineTest.php` — every edge, replay, stale, conflict, unknown.
- `tests/Unit/StateOrderingMatrixTest.php` — explicit payment and refund ordering matrices.
- `tests/Integration/wp-cli-payment-flow.php`, `wp-cli-refunds.php`, `wp-cli-lifecycle.php` —
  the machines applied to real WooCommerce orders in both storage modes.
