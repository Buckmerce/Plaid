# API Reference Index and Freshness Protocol

## 1. Purpose

This file tells contributors which external API documentation governs PayBridge.

Local API notes are not a substitute for official documentation. They define the project's integration contract and link to the authoritative source.

## 2. Mandatory API documents

Last full re-verification of every Plaid endpoint PayBridge uses: 2026-10-01 (see
`api/PLAID_TRANSFER.md` header). WooCommerce contracts re-verified the same day against the
8.5.2, 8.6.x, 8.7.0 and 11.1.2 sources (`api/WOOCOMMERCE.md`).


- [`api/README.md`](api/README.md) — index, and why the Plaid documentation copy is local only
- [`api/PLAID_TRANSFER.md`](api/PLAID_TRANSFER.md)
- [`api/WOOCOMMERCE.md`](api/WOOCOMMERCE.md)
- [`api/WORDPRESS.md`](api/WORDPRESS.md)
- [`api/ACTION_SCHEDULER.md`](api/ACTION_SCHEDULER.md)

## 3. Freshness protocol

Before implementing a third-party behavior:

1. read the local API document;
2. open its official source links;
3. verify endpoint/API/status semantics;
4. record the verification date in the local doc if materially revalidated;
5. update local contract notes if changed;
6. update tests/fixtures;
7. update ADR if architecture must change.

## 4. Never infer APIs

Do not invent:

- endpoints;
- request fields;
- webhook fields;
- response statuses;
- compatibility flags;
- environment availability.

If official docs are unclear, keep implementation conservative and document the unresolved assumption.

## 5. Source priority

1. official Plaid API/docs;
2. official WooCommerce developer docs/source contract;
3. official WordPress developer docs;
4. official Action Scheduler docs;
5. repository docs/ADRs;
6. third-party blog/forum content only as secondary context.
