# Coding Standards

## PHP

- PHP 8.1+ baseline.
- `declare(strict_types=1);` in project-owned PHP files.
- WordPress Coding Standards plus project exceptions only when justified.
- Strong parameter/return/property types.
- Prefer small final services/value objects where appropriate.
- No dynamic properties.
- No silent catches.
- Typed domain exceptions.
- `mixed` normalized at external boundaries.

## WordPress

- sanitize input according to type;
- validate domain constraints explicitly;
- escape on output;
- use capabilities + nonces correctly;
- use Settings/HTTP/REST/WooCommerce APIs rather than custom reinvention.

## SQL

Prefer platform APIs. For custom tables:

- use `$wpdb` prepared statements where values are interpolated;
- define exact indexes/uniqueness;
- avoid unbounded scans;
- use atomic database semantics for locks/event claims.

## JavaScript/TypeScript

- modern modules/build;
- no global pollution;
- explicit loading/error states;
- no credentials;
- no business authority in frontend;
- prevent duplicate user actions;
- accessible controls.

## CSS

Namespace selectors with `.pbfp-` where practical. Avoid global element styling and unnecessary `!important`.

## Exceptions

Suggested hierarchy:

```text
PayBridgeException
├── ConfigurationException
├── PaymentException
│   ├── PaymentAttemptBusyException
│   └── InvalidPaymentStateTransition
├── PlaidException
│   ├── PlaidApiException
│   ├── PlaidNetworkException
│   └── PlaidMalformedResponseException
├── WebhookVerificationException
└── PersistenceException
```

## Comments

Explain why, not what. Payment-risk assumptions deserve comments and links to ADR/docs.

## Naming

Use domain language:

- `TransferIntent`, not generic `Invoice`;
- `PaymentAttempt`, not `RequestData`;
- `TransferEvent`, not `Callback`;
- `OrderPaymentProjector`, not `Helper`.
