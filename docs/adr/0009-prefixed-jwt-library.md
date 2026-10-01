# ADR-0009: Bundle firebase/php-jwt, prefixed with Strauss, for webhook verification

**Status:** Accepted

## Context

Plaid signs webhooks with an ES256 JWT in the `Plaid-Verification` header
(see `docs/api/PLAID_TRANSFER.md` and `docs/WEBHOOKS_AND_EVENTS.md`). Verifying it needs
JWK → PEM conversion for P-256 keys, JOSE signature decoding (raw `r||s` → DER) and strict
header/claim validation. Hand-written code for this is a classic source of signature-bypass
bugs (algorithm confusion, `none`, malformed DER).

WordPress has no JWT API. Many plugins bundle their own copy of `firebase/php-jwt` in the
global `Firebase\JWT` namespace; whichever plugin loads first wins, so a store could run
PayBridge against an older, incompatible or vulnerable copy.

## Decision

- Use `firebase/php-jwt` (^7.0, BSD-3-Clause) for JWK parsing and ES256 verification.
- Bundle it with **Strauss** into `vendor-prefixed/` under the namespace
  `PayBridge\Plaid\Vendor\Firebase\JWT` (classmap prefix `PayBridge_Plaid_Vendor_`).
  `composer install` regenerates it; the directory is committed so the release build needs
  no Composer, and CI fails when it drifts from `composer.lock`.
- `WebhookVerificationService` still enforces PayBridge's own rules around the library:
  `alg` must be `ES256`, `kid` is required and resolved only through
  `/webhook_verification_key/get`, `iat` must be within five minutes, and the
  `request_body_sha256` claim is compared with `hash_equals()`.
- Composer platform is pinned to PHP 8.1 so `composer.lock` installs on every supported
  PHP version.

## Consequences

- No conflicts with other plugins that ship `Firebase\JWT`.
- Security updates of the library require `composer update firebase/php-jwt`, which
  regenerates `vendor-prefixed/`; the release ZIP includes the library licence.
- The release ZIP contains only the prefixed sources and autoloader, never `vendor/`.
