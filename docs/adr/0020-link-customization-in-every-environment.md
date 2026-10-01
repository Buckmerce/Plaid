# ADR-0020: A Link customization is required in Sandbox and Production

**Status:** Accepted (1.0.0). Supersedes the "Sandbox may use Plaid's default customization" part
of ADR-0013.

## Context

Transfer UI integration step 1 (<https://plaid.com/docs/transfer/using-transfer-ui/>, verified
2026-09-30): "In the Plaid Dashboard, create a Link customization with 'Account Select' set to the
'Enabled for one account' selection", then pass its name as `link_customization_name` to
`/link/token/create`. ADR-0013 required it only in Production; Sandbox used Plaid's unspecified
default customization. The real Sandbox release gate therefore exercised a different Transfer UI
shape than Production, and a merchant could validate a Sandbox setup that would not match
Production.

## Decision

- `Settings::link_customization_required()` is true in both environments.
  `GatewayAvailability::MISSING_LINK_CUSTOMIZATION` hides Pay by Bank, the configuration status is
  "Incomplete", diagnostics show FAIL, and `link_customization_name` is always sent to
  `/link/token/create`. Plaid's default customization is never relied upon.
- The real Sandbox gate requires `PAYBRIDGE_PLAID_SANDBOX_LINK_CUSTOMIZATION` (a repository
  variable or secret in CI). Without it the gate exits 78 ("blocked by a missing input"), which a
  release (`require_sandbox: true`) treats as a failure. The Transfer UI automation asserts that
  the account-selection pane offers no multi-account checkboxes.
- The Plaid test double rejects Link token requests without a customization, so every integration
  and browser test proves the name is sent.

## Consequences

- Sandbox and Production run the same Transfer UI configuration; misconfigured customizations
  surface in Sandbox (`INVALID_LINK_CUSTOMIZATION` is recorded and shown).
- Existing Sandbox setups without a customization must add one before Pay by Bank is offered again.
- Tests: `tests/Unit/GatewayAvailabilityTest.php`, `tests/Integration/wp-cli-smoke.php`,
  `tests/Integration/wp-cli-lifecycle.php`, `tests/E2E/sandbox-transfer-ui.js`.
