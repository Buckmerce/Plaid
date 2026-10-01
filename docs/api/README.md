# Provider and platform API contracts

This directory holds PayBridge's **integration contracts**: exactly which endpoints, fields,
statuses, webhooks and hooks PayBridge relies on, how it uses them, what it verified and when.
They are maps, not replacements for the official documentation. Before changing integration code,
open the official source linked in the contract and verify it (AGENTS.md §3).

| Contract | Official source | Last verified |
|---|---|---|
| [`PLAID_TRANSFER.md`](PLAID_TRANSFER.md) — Transfer UI, intents, Link tokens, transfers, events, refunds, webhooks, Sandbox, return-retry rules | <https://plaid.com/docs/transfer/>, <https://plaid.com/docs/api/products/transfer/> | 2026-10-01 |
| [`WOOCOMMERCE.md`](WOOCOMMERCE.md) — gateway, HPOS, Blocks, refunds, order-pay, hooks | <https://developer.woocommerce.com/> and the WooCommerce sources of the supported versions | 2026-09-30 (8.5.2, 8.7.0, 11.1.2) |
| [`WORDPRESS.md`](WORDPRESS.md) — REST API, options, cron, i18n, uninstall | <https://developer.wordpress.org/> | 2026-09-30 |
| [`ACTION_SCHEDULER.md`](ACTION_SCHEDULER.md) — background jobs | <https://actionscheduler.org/> | 2026-09-30 |

## The Plaid documentation copy is local only

Plaid publishes every documentation page as Markdown
(`https://plaid.com/docs/<path>/index.html.md`). A copy is useful for offline reading and
searching, but it is **not committed**:

- the content is © Plaid Inc. and is not ours to redistribute in a public repository;
- a committed copy silently becomes stale and starts to look authoritative.

`bash scripts/fetch-plaid-docs.sh` downloads the pages PayBridge depends on (Transfer guides,
Transfer API reference, webhook verification, Sandbox, Link, Transfer errors, changelog) into
`docs/api/plaid-mirror/` (git-ignored); every file records its source URL and retrieval date. An
older, fuller local snapshot (2026-09-29, 294 pages) may exist in the same directory on a
developer's machine. Either way, **the live page is the authority**: when it differs from a
contract here, update the contract (and an ADR when architectural) before changing code.

## Update procedure

1. Read the contract and the linked official pages (optionally refresh the local copy).
2. Compare every field/status/behavior PayBridge uses; note the verification date in the contract.
3. Update the contract, the test double (`tests/fixtures/plaid-mock.php`) and the tests when the
   external contract changed; add or supersede an ADR when the architecture must change.
4. Only then change the implementation.
