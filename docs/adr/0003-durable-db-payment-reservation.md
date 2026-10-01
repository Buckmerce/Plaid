# ADR-0003: Durable database payment reservation

**Status:** Accepted

## Context

WooCommerce payment initiation can run concurrently or retry after ambiguous failures.

## Decision

Use a database-backed lease/reservation around remote payment creation rather than process-memory locks or timing assumptions.

## Consequences

- unique ownership token;
- bounded lease;
- contention fails closed;
- lease expiry requires state re-check;
- DB failure prevents unprotected remote creation.
