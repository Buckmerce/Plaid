# ADR-0013: Transfer UI configuration is fixed where Plaid defines it

**Status:** Accepted (1.0.0); "Sandbox may use the default customization" superseded by ADR-0020. The local paths cited below moved to the git-ignored Plaid mirror (docs/api/README.md); the official pages are https://plaid.com/docs/transfer/using-transfer-ui/ and https://plaid.com/docs/transfer/creating-transfers/

## Context

The 0.1.0 settings exposed a free choice of ACH class (WEB, PPD, CCD, TEL), an optional Link
customization and an order-number statement description. Plaid documentation (verified
2026-09-30, `docs/api/transfer/using-transfer-ui.md`, `creating-transfers.md`) states:

- Transfer UI "is compliant with Nacha WEB guidelines" and captures the customer's
  authorization over the Internet — the definition of the WEB entry class. PPD, CCD and TEL
  describe authorizations Transfer UI does not capture (written, corporate, telephone).
- Integration step 1: create a Link customization with Account Select "Enabled for one
  account" and pass its name as `link_customization_name` to `/link/token/create`. Its
  language must match the `language` parameter.
- The `description` appears on the customer's bank statement after the company name; ACH
  shows 10 characters; describe the purpose (e.g. `PAYMENT`), avoid granular values such as
  order numbers.
- `user.legal_name` is required.

## Decision

- `ach_class` is always `web`; the setting was removed. A stored legacy value is ignored.
- Production requires a Link customization name: without it the gateway is not offered
  (`GatewayAvailability::MISSING_LINK_CUSTOMIZATION`), the configuration status is
  "Incomplete" and diagnostics show `FAIL`. Sandbox may use Plaid's default customization.
  A Plaid rejection of the customization (`INVALID_LINK_CUSTOMIZATION`) is recorded and shown.
- A "Bank statement description" setting (default `PAYMENT`) is normalized to upper-case
  ASCII letters, digits and spaces, at most 10 characters.
- The legal name is the billing first + last name; both are required. No fallback such as
  "Customer" and no company-name substitution. Classic Checkout validates before creating the
  order; Blocks and the payment page refuse before anything is reserved or sent to Plaid.

## Consequences

- A merchant cannot misclassify web debits (misclassification can cause returns outside the
  expected window or loss of Transfer access).
- Production configuration fails closed instead of running Transfer UI with a
  multi-account default.
- Implementation: `Settings`, `GatewayAvailability`, `TransferIntentRequest`,
  `PayBridgeGateway::validate_fields()`; tests: `tests/Unit/GatewayAvailabilityTest.php`,
  `tests/Unit/PlaidServicesTest.php`, `tests/Integration/wp-cli-lifecycle.php`.
