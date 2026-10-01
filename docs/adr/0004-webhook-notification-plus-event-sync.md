# ADR-0004: Webhook notification plus Transfer event sync

**Status:** Accepted

## Context

Plaid Transfer webhooks notify that events exist; robust processing needs authoritative event retrieval and replay safety.

## Decision

Verify webhook cryptographically, enqueue work, then use `/transfer/event/sync` and durable event persistence.

## Consequences

- webhook handler stays fast;
- duplicate webhooks are harmless;
- event processing is recoverable;
- reconciliation remains a second recovery path.
