# Security Architecture and Threat Model

## 1. Assets to protect

- merchant Plaid credentials;
- payment and refund integrity (no duplicate money movement, no false confirmation);
- WooCommerce order ownership;
- transfer and refund identifiers;
- customer personal data;
- webhook authenticity;
- financial audit trail (payment attempts, refunds, returns).

## 2. Threats and controls

| Threat | Control |
|---|---|
| **Payment spoofing** — attacker calls `/complete` and claims Link success | The browser only triggers a check; the result comes from `/transfer/intent/get` for the stored intent. Browser-supplied status, transfer ID or amount are ignored (tested). |
| **Amount tampering** | Amount comes from the WooCommerce order via an immutable snapshot; Plaid objects are compared with it (cent-exact decimal comparison); mismatches go to manual review. |
| **IDOR** | Customer routes require the order key AND the logged-in owner or, for guests, an HMAC session grant created by `process_payment()`, plus a payment nonce. A numeric ID is never authorization; denials are indistinguishable. |
| **Duplicate transfer** | Advisory lock + durable reservation + intent reuse; Link tokens only for the stored intent (`docs/CONCURRENCY_IDEMPOTENCY.md`). |
| **Duplicate or excessive refund** | Per-order refund lock, recomputed remaining amount (cents), unique idempotency key and WooCommerce refund binding, Plaid idempotency key, double-submit guard, uncertain refunds block further refunds (ADR-0016). |
| **Webhook forgery/replay** | `Plaid-Verification` ES256 JWT only (alg allowlist before any key lookup; `none`/HS256 rejected), `kid` format, JWK from `/webhook_verification_key/get` (cached 24 h, unknown IDs negatively cached 5 min, max 10 lookups/min), signature via the maintained, namespace-prefixed `firebase/php-jwt`, `iat` within 5 min (+60 s skew), constant-time SHA-256 body hash, 64 KiB body limit. A verified webhook only queues `/transfer/event/sync`; its body never changes state. |
| **JWK poisoning / algorithm confusion** | Keys must be `kty=EC`, `crv=P-256`, `alg=ES256`, matching `kid`, not expired; the parsed key is pinned to ES256. |
| **Secret leakage** | Secret never rendered (write-only field), never sent to the browser, never in exceptions or notes; logs pass a recursive redactor (secret, token, authorization, cookie, verification, account/routing numbers, legal name, email, phone, address, …). |
| **SSRF** | Plaid hosts are two constants (`PlaidEnvironment`); the endpoint allowlist is fixed in `PlaidClient`; `redirection => 0`; no setting or request field can change a URL. |
| **Open redirect** | Redirects use WooCommerce order URLs and `wp_safe_redirect()` to the referer/order edit URL only. |
| **Privilege escalation** | Every admin action (`admin-post`) checks `manage_woocommerce` and a per-order nonce: sync, cancel transfer, resolve review, dismiss alert, test connection. Settings are saved by WooCommerce's nonce-checked settings API. |
| **Environment confusion** | Environment is part of the snapshot; objects are never queried with another environment's credentials; `/sandbox/*` endpoints throw in Production at the client level. |
| **Unsafe credential change** | Account/environment change guard refuses switching accounts while Production payments are monitored (ADR-0015). |
| **Cross-account event contamination / cursor poisoning** | Event identity, cursor, epoch, sync lock and refund identity are scoped by environment + account fingerprint (ADR-0018); another account's event 5 is never a duplicate of ours, our cursor is never advanced by another stream, an order of another account is never changed (`account_mismatch`), refund IDs never match across accounts. A corrupted stored event is abandoned instead of blocking the stream. |
| **Illegal re-debit of a returned payment** | `ReturnRetryPolicy` at the single new-debit entry point: after any return (R10, R07, R11, … and R01/R09, which Transfer UI cannot mark as "Retry 1/2") no new intent, Link token or debit is created for the order (ADR-0019). |
| **Abuse of shopper endpoints** | Order key + ownership + nonce, plus per-order rate limits: 15 Link tokens and 40 completion checks per 10 minutes. No CAPTCHA (no demonstrated need). |
| **Webhook endpoint abuse** | Invalid requests are rejected before any Plaid call except the rate-limited key lookup; rejection diagnostics are throttled to one write per minute. |

## 3. REST rules

| Route | Methods | Authorization | Schema |
|---|---|---|---|
| `/paybridge-for-plaid/v1/link-token` | POST | order key + owner/session grant + payment nonce | `order_id` int ≥ 1, `order_key` `^wc_order_[A-Za-z0-9]{1,40}$`, `payment_nonce` `^[a-f0-9]{10}$` |
| `/paybridge-for-plaid/v1/complete` | POST | same | same |
| `/paybridge-for-plaid/v1/webhook` | POST | cryptographic (inside the callback, before anything else) | raw body ≤ 64 KiB |

Errors return generic messages and codes; no provider payloads, class names or traces.

## 4. Customer- and merchant-facing errors

- Customers: "Bank payment could not be started", "The bank connection was closed…",
  "This order can no longer be paid", "Pay by Bank is not available for this order right now",
  missing account-holder name, rate-limited. Never secrets, raw JSON, HTTP auth errors, class
  names or database details.
- Merchants: classified connection results (invalid credentials, product not enabled,
  permission denied, rate limited, Plaid unavailable, network error, Link customization,
  funding-account conflicts), refund rejections with Plaid's error code and explanation,
  return/refund alerts. Still no secrets.

## 5. Secrets

- The stored secret is never rendered; blank save keeps it; "Remove stored secret" clears it
  (refused by the account guard while Production payments are monitored).
- Auth headers are never logged; exception messages never contain credentials.
- Sandbox credentials for tests live only in the gitignored `.env` / CI secrets.
- `scripts/audit-source.sh` (CI job "Documentation integrity and source audit") fails when the
  source archive (`git archive HEAD`) or a given archive contains `.env` files, Playwright traces,
  IDE state, local caches, dumps or credential patterns, when `.env.example` holds real values, or
  when a credential value of the local `.env` appears in any tracked file or in the Git history
  (only variable names are printed). Audit of 2026-09-30: the Sandbox Client ID and secret never
  reached Git; only the reserved ngrok host name (not a credential) appeared in early commits'
  `.env.example`. A local, untracked source archive in the project root that includes `.env` must
  never be shared; if it was, rotate the Sandbox secret.

## 6. Input and output

Sanitize at input (settings sanitizers, `sanitize_text_field`, `absint`, schema patterns),
validate domain constraints separately (Money, Settings normalizers, DTO field readers),
escape at output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`). Provider text shown to
merchants is stripped of markup and truncated.

## 7. Dependency security

- Runtime: `firebase/php-jwt` (^7.0, BSD-3), prefixed with Strauss (ADR-0009), pinned by
  `composer.lock`; CI fails when `vendor-prefixed/` drifts from the lock.
- CI: `composer audit --locked --no-dev` (blocking), `composer audit --locked` (blocking),
  `npm audit --audit-level=high` (blocking: the build toolchain produces shipped assets),
  GitHub dependency review on pull requests (fail on high).
- Accepted risks: none recorded (all audits clean on 2026-09-30).

## 8. Security release gate

No release with a known critical/high issue in duplicate money movement (payments or
refunds), refund overpayment, webhook authentication, IDOR, secret exposure, order amount
integrity, environment/account confusion or privilege escalation. The final audit for 1.0 is
recorded in `CHANGELOG.md` (§ Security audit) and the release report.
