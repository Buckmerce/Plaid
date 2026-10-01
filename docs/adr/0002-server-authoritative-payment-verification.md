# ADR-0002: Server-authoritative payment verification

**Status:** Accepted

## Context

Browser callbacks can be replayed or forged and do not represent final funds movement.

## Decision

Browser callbacks only trigger server verification. PayBridge retrieves authoritative Plaid state server-side before projecting payment status.

## Consequences

- Browser never supplies trusted amount/status/transfer ID.
- `/transfer/intent/get` is part of completion flow.
- Later lifecycle comes from Transfer event APIs/reconciliation.
