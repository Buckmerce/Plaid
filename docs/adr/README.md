# Architecture Decision Records

ADRs document decisions whose rationale future contributors must understand.

## Rules

- ADRs are append-only historical records.
- Do not silently rewrite an accepted decision when architecture changes.
- Add a new ADR that supersedes the old one.
- Link relevant implementation/docs.

## Status values

- Proposed
- Accepted
- Superseded
- Rejected

## Current ADRs

- `0001-use-plaid-transfer-ui-for-mvp.md`
- `0002-server-authoritative-payment-verification.md`
- `0003-durable-db-payment-reservation.md`
- `0004-webhook-notification-plus-event-sync.md`
- `0005-explicit-payment-state-machine.md`
- `0006-woocommerce-crud-hpos-first.md`
- `0007-ambiguous-intent-and-ledger.md`
- `0008-payment-index-table.md`
- `0009-prefixed-jwt-library.md`
- `0010-verified-persistence-and-foreign-transfers.md`
- `0011-payment-epoch.md`
- `0012-returned-payment-semantics.md` — returned payments: WooCommerce Failed + explicit PayBridge marker (custom status evaluated and rejected)
- `0013-transfer-ui-configuration.md` — fixed WEB class, required Link customization in Production, statement descriptor, real legal name
- `0014-maintenance-and-return-windows.md` — maintenance independent of the enabled switch; monitoring until Plaid return windows close (supersedes 0.1.0 scheduling)
- `0015-plaid-account-change-guard.md` — Plaid account identity and the account/environment change guard
- `0016-refund-architecture.md` — native refunds as a separate idempotent refund domain
- `0017-payment-attempt-history.md` — durable attempt history and repayment as a new attempt
- `0018-account-scoped-event-streams.md` — event identity, cursor, epoch, sync lock and refund identity scoped by environment + Plaid account (schema 3)
- `0019-returned-transfer-retry-policy.md` — no same-order bank debit after an ACH return (Plaid retry rules, Transfer UI has no retry marking)
- `0020-link-customization-in-every-environment.md` — Link customization required in Sandbox and Production
- `0021-maintenance-follows-operational-work.md` — reconciliation only while real work exists; settled refunds watched as long as the refunded debit
- `0022-minimum-woocommerce-8-7.md` — WooCommerce 8.7 minimum (HPOS refund props persisted)
