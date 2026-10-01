# Privacy and External-Service Compliance

## 1. Principle

Collect, transmit and retain the minimum data required to execute and support the payment.

## 2. Third-party service disclosure

`readme.txt` and privacy-policy helper content should explain that PayBridge communicates with Plaid and link to applicable Plaid terms/privacy information.

Disclose what categories of data are sent and why.

## 3. Data minimization

Do not persist:

- bank login credentials;
- full account/routing numbers unless a future documented flow strictly requires them;
- unnecessary Link metadata;
- provider payloads wholesale.

Persist only correlation/state/support data required by the integration.

## 4. Logs

Avoid PII in logs. Redact secrets and sensitive values recursively.

Support exports must be sanitized by design.

## 5. WordPress privacy integration

Consider `wp_add_privacy_policy_content()` for suggested privacy-policy text.

If PayBridge later stores additional personal data outside WooCommerce, evaluate whether WordPress personal-data exporter/eraser hooks are appropriate.

Financial/audit retention obligations may justify retaining some records; if so, disclose the retention policy rather than silently deleting them.

## 6. Telemetry

No telemetry in MVP.

Any future telemetry must be explicit opt-in and documented.

## 7. Data PayBridge 1.0 stores and sends

- **Sent to Plaid:** order amount and currency, the merchant's bank statement description,
  the billing first + last name (required legal name), billing email, correlation metadata
  (order ID, random attempt ID, environment, a non-reversible store marker), refund amounts and
  idempotency keys (hashes). Never bank credentials; phone numbers are not sent.
- **Stored on the order:** Plaid identifiers, statuses, provider timestamps and return windows,
  sanitized failure/return codes and a short provider reason, the attempt history, a non-secret
  Plaid account fingerprint (hash of the client ID).
- **PayBridge tables:** event identities and minimal event data (IDs, types, amounts, codes),
  the payment index, and refund rows (amounts, Plaid IDs, statuses). No personal data beyond the
  order ID.
- **Retention:** financial/audit data is kept by default (returns can arrive about three months
  after settlement); the opt-in uninstall cleanup deletes PayBridge tables and options but
  never order data.
