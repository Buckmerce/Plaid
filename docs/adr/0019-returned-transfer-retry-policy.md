# ADR-0019: No same-order bank debit after an ACH return (return retry policy)

**Status:** Accepted (1.0.0). Supersedes the "customer retry / repayment after a return" parts of
ADR-0012 and ADR-0017. Normal retries after failures before money moved are unchanged.

## Context

PayBridge 0.x/1.0 drafts archived a returned attempt and let the customer pay the same order
again through the WooCommerce pay link: a new Transfer Intent, a new Transfer UI authorization and
a new ACH debit. Plaid restricts reprocessing returned transfers. Verified on 2026-09-30 against
<https://plaid.com/docs/api/products/transfer/initiating-transfers/#transfercreate> and
<https://plaid.com/docs/transfer/troubleshooting/>:

- "If reprocessing a returned transfer, the `description` field must be `"Retry 1"` or
  `"Retry 2"`. You may retry a transfer up to 2 times, within 180 days of creating the original
  transfer. Only transfers that were returned with code `R01` or `R09` may be retried."
- "Unlike other ACH return codes, you cannot resubmit a payment after encountering an R10 error."

These rules are documented **only for `/transfer/create`**. PayBridge originates debits with
Transfer UI (`/transfer/intent/create`, ADR-0001). The intent's `description` is the merchant's
statement descriptor; the Transfer UI documentation
(<https://plaid.com/docs/transfer/using-transfer-ui/>,
<https://plaid.com/docs/api/products/transfer/account-linking/>) describes no way to mark an
intent or its transfer as a retry of a returned transfer, and no retry semantics at all besides
the customer re-trying an NSF-declined **authorization** (the intent stays `PENDING`).

## Decision

Option B of the release task: **no documented compliant retry flow exists for Transfer UI, so
PayBridge 1.0 never debits an order again after one of its transfers was returned.**

- `Payment\ReturnRetryPolicy` evaluates Plaid's rules completely for an order's lineage of
  money-moving attempts (original returned transfer, every later transfer counted as a retry,
  return codes, original creation time, flow) and returns a `ReturnRetryDecision`:
  `NOT_RETURNED`, `ALLOW_RETRY_1`, `ALLOW_RETRY_2` (only possible for the `/transfer/create`
  flow), `BLOCK_RETURN_CODE` (anything but R01/R09, unknown codes included — R10, R07, R11, R02…),
  `BLOCK_RETRY_LIMIT` (two retries used), `BLOCK_WINDOW_EXPIRED` (180 days after the original, or
  its age unknown), `BLOCK_UNSUPPORTED_FLOW` (eligible under Plaid's rules, but Transfer UI cannot
  mark the debit as "Retry 1/2").
- The single choke point for new debits, `PaymentAttemptService::ensure_active_intent()` (checkout,
  pay link, Link token), refuses a blocked order with `ReturnedPaymentRetryException` before any
  reservation or Plaid call. The gateway is not offered on the order-pay page of such an order
  (whichever payment method the order currently names — the decision follows the order's
  PayBridge attempt lineage),
  the order-pay form and the PayBridge payment page explain why (no return codes shown to the
  customer), the REST Link-token route answers `409 paybridge_not_payable`, and "Let the customer
  pay again" (manual review) is unavailable.
- The merchant sees the decision in a private order note, the return email, and the order panel
  (why, per return code), and collects the payment another way. The returned attempt stays the
  order's current, auditable attempt; nothing is archived or replaced.
- Failures before money moved (intent failed/declined, transfer `failed` or `cancelled`) are not
  returns: the customer may pay again with a new attempt (ADR-0017).

## Consequences

- PayBridge can never originate an illegal reprocessing of a returned debit (R10 and other
  unauthorized returns included) or exceed Plaid's retry limits.
- Merchants lose the convenience of a one-click re-debit after an R01/R09 return. Supporting
  compliant retries would require the `/transfer/create` flow with Plaid-authorized retries — a
  post-v1 architecture change that needs its own ADR; the policy already encodes the rules.
- Orders repaid after a return before 1.0 count those repayments as used retries (conservative).
- Tests: `tests/Unit/ReturnRetryPolicyTest.php`, `tests/Integration/wp-cli-lifecycle.php`,
  `tests/Integration/wp-cli-payment-flow.php`, `tests/E2E/browser-smoke.js`, real Sandbox
  `$33.33` (R01) in `scripts/test-sandbox-e2e.sh`.
