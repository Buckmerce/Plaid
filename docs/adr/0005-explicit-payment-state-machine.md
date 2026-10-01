# ADR-0005: Explicit payment state machine

**Status:** Accepted

## Context

ACH transfers have asynchronous, potentially out-of-order states and can return after apparent success.

## Decision

All PayBridge payment transitions pass through a centralized state machine.

## Consequences

- provider event ordering is validated;
- duplicate events are no-ops;
- return states cannot silently regress to success;
- WooCommerce status is a projection, not the sole payment record.
