# ADR-0006: WooCommerce CRUD / HPOS-first order integration

**Status:** Accepted

## Context

WooCommerce HPOS decouples order storage from classic WordPress posts/postmeta.

## Decision

PayBridge order logic uses WooCommerce CRUD/query APIs and tests HPOS as a first-class environment.

## Consequences

- no direct order business logic against `wp_posts`/`wp_postmeta`;
- compatibility declaration only after passing tests;
- integrations remain viable as WooCommerce storage evolves.
