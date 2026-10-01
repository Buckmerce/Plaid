# PayBridge Development Documentation

This directory is the engineering source of truth for **PayBridge for Plaid — WooCommerce Pay by Bank**.

## Required reading order

For any substantial engineering task, read in this order:

1. [`DEVELOPMENT_HANDBOOK.md`](DEVELOPMENT_HANDBOOK.md)
2. [`ARCHITECTURE.md`](ARCHITECTURE.md)
3. [`API_REFERENCE.md`](API_REFERENCE.md)
4. [`api/PLAID_TRANSFER.md`](api/PLAID_TRANSFER.md)
5. [`api/WOOCOMMERCE.md`](api/WOOCOMMERCE.md)
6. [`SECURITY.md`](SECURITY.md)
7. [`PAYMENT_LIFECYCLE.md`](PAYMENT_LIFECYCLE.md)
8. [`STATE_MACHINE.md`](STATE_MACHINE.md)
9. [`CONCURRENCY_IDEMPOTENCY.md`](CONCURRENCY_IDEMPOTENCY.md)
10. task-specific documents below.

## Documentation map

| Document | Purpose |
|---|---|
| `DEVELOPMENT_HANDBOOK.md` | Master engineering rules and development workflow |
| `ARCHITECTURE.md` | Component boundaries and dependency direction |
| `PAYMENT_LIFECYCLE.md` | End-to-end checkout and asynchronous ACH lifecycle |
| `STATE_MACHINE.md` | Explicit payment states and allowed transitions |
| `PLAID_INTEGRATION.md` | Provider integration design |
| `WEBHOOKS_AND_EVENTS.md` | Webhook verification, event sync and event processing |
| `CONCURRENCY_IDEMPOTENCY.md` | Duplicate-payment protection and failure recovery |
| `DATA_MODEL.md` | Tables, order metadata, ownership and migrations |
| `WOO_COMMERCE_INTEGRATION.md` | Gateway, Blocks, HPOS and order handling |
| `SECURITY.md` | Threat model and mandatory controls |
| `PRIVACY_COMPLIANCE.md` | Data minimization, third-party disclosure and retention |
| `OBSERVABILITY_SUPPORT.md` | Logs, diagnostics, Site Health and operator UX |
| `TESTING_QA.md` | Test pyramid and acceptance matrix |
| `CI_CD_RELEASE.md` | CI, packaging and release requirements |
| `CODING_STANDARDS.md` | PHP/JS/CSS/domain coding rules |
| `RUNBOOKS.md` | Operational incident procedures |
| `MILESTONES.md` | Engineering roadmap and release gates |
| `API_REFERENCE.md` | API documentation index and freshness protocol |
| `api/*` | Provider/platform API contracts and official sources (`api/README.md`; the Plaid docs copy in `api/plaid-mirror/` is local and git-ignored) |
| `adr/*` | Architecture Decision Records |

## Documentation policy

Documentation is version-controlled with code. When behavior changes, docs must change in the same pull request.

Third-party API documents in `docs/api/` are **integration maps**, not replacements for official documentation. Before changing integration behavior, verify linked official documentation and update these files if the external API has changed.
