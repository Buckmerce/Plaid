# ADR-0001: Use Plaid Transfer UI for MVP

**Status:** Accepted

## Context

A custom ACH authorization UI increases compliance, consent and proof-of-authorization complexity.

## Decision

MVP uses Plaid Transfer UI for one-time WooCommerce Pay by Bank payments.

## Consequences

- Transfer Intent precedes Link token.
- Transfer UI handles the authorization experience supported by Plaid.
- MVP is intentionally not a recurring-payment architecture.
- Future recurring/platform capabilities require separate evaluation.
