# ADR-0015: Plaid account identity and the account/environment change guard

**Status:** Accepted (1.0.0); account identity extended to event streams, cursors, epochs and refunds by ADR-0018

## Context

Transfers and refunds can only be read by the Plaid client that created them. If a merchant
replaces the Client ID (another Plaid team) or switches environment while ACH transfers are
still in flight or inside their return window, PayBridge can no longer detect returns or
refund outcomes for those payments. The environment name alone does not identify the account
(two Production accounts are both "production"). A secret alone must never be stored for
comparison.

Plaid issues one `client_id` per team; the Sandbox and Production secrets belong to it and a
secret rotation keeps the `client_id`.

## Decision

- **Account identity** = `substr(sha256('paybridge-plaid-account:' . lower(client_id)), 0, 16)`
  (`Settings\AccountIdentity`). It is non-secret (the client_id is an identifier, not a
  credential) and never involves the secret. Each attempt records it in the payment index
  (`account_fp`) and on the order (`_pbfp_account_fingerprint`); refunds record it too.
  Schema-1 rows are attributed to the account configured at upgrade time.
- **Guard** (`Settings\AccountChangeGuard`, on `pre_update_option_woocommerce_paybridge_plaid_settings`
  so the settings screen, the WooCommerce REST API and WP-CLI are all covered): a change of
  environment or Client ID, or removing the secret, while the previous account/environment
  still has monitored payments (index `reconcile_after` set, or a non-terminal state) or open
  refunds:
  - **Production:** refused — the previous environment, Client ID and secret are kept, all
    other settings are saved, the merchant sees an error notice (a WP-CLI warning), and a
    security log entry is written.
  - **Sandbox:** allowed with a warning (no real money).
- Rotating the secret of the same Client ID is always allowed.
- At runtime, reconciliation only reads rows of the configured account; rows of another
  account are reported in diagnostics and Site Health instead of being queried with the wrong
  credentials. Refund eligibility requires the order's account to match.

## Consequences

- A merchant cannot accidentally make open Production payments unmonitorable.
- Switching accounts requires waiting until diagnostics show no monitored payments (the
  unauthorized return window: about three months after the last settlement). This is a
  deliberate v1 limitation; multi-account credentials are post-v1.
- Tests: `tests/Unit/GatewayAvailabilityTest.php` (identity), `tests/Integration/wp-cli-lifecycle.php` (guard).
