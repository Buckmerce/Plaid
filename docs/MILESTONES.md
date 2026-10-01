# Engineering Milestones

## M0 — Identity and foundation

- independent PayBridge namespace/slug/data ownership;
- zero legacy provider behavior;
- strict tooling/build/CI foundation.

## M1 — Plaid provider infrastructure

- centralized client;
- Sandbox/Production configuration;
- safe credentials;
- connection diagnostics.

## M2 — Transfer Intent + payment page

- immutable payment snapshot;
- durable reservation;
- intent create/reuse;
- protected payment page;
- Link token.

## M3 — Transfer UI completion

- Transfer UI;
- server `/transfer/intent/get` verification;
- transfer ID persistence;
- safe WooCommerce async state.

## M4 — Webhook security + event sync

- full Plaid webhook verification;
- event sync;
- event persistence/idempotency;
- Action Scheduler.

## M5 — Lifecycle and reconciliation

- state machine;
- success/failure/return handling;
- lost-webhook recovery;
- manual sync.

## M6 — WooCommerce completeness

- Classic;
- Blocks;
- HPOS;
- guest/logged-in/order-pay flows.

## M7 — Operations

- diagnostics;
- Site Health;
- order panel;
- sanitized support report/logging.

## M8 — Quality/release

- full test matrix;
- Plugin Check;
- package smoke;
- real Sandbox `$11.11/$22.22/$33.33` gates;
- reproducible ZIP.

## Status (0.1.0, 2026-09-29)

M0–M8 are implemented. Evidence: `docs/TESTING_QA.md` §11 (suites) and
`docs/CI_CD_RELEASE.md` §9 (pipeline). The real Plaid Sandbox gate passed for $11.11 (Checkout
block), $22.22 and $33.33 (Classic checkout) with locally supplied Sandbox credentials, both
on a local URL and on a public HTTPS URL through ngrok, where the lifecycles were driven by
genuine Plaid-signed webhooks and forged, tampered, replayed and stale webhooks were rejected
or harmless (`npm run test:sandbox:ngrok`). Not exercised: GitHub Actions itself (the
workflow steps were run locally) and Production credentials.

## M9 — v1.0 production hardening (2026-09-30)

- maintenance independent of the enabled switch; return-window monitoring (ADR-0014);
- Transfer UI configuration fixed where Plaid defines it (WEB, Link customization, statement
  description, legal name — ADR-0013);
- native full/partial refunds with idempotency, events, reconciliation and return-after-refund
  protection (ADR-0016);
- explicit returned-payment semantics, attempt history, safe repayment after failures (ADR-0012, ADR-0017; after returns see M10);
- Plaid account/environment change guard (ADR-0015);
- classified connection test, operational diagnostics, configuration status, Site Health,
  WP-Cron/overdue-job detection, merchant actions (cancel, manual review);
- CI: dependency audits, shellcheck, MySQL + MariaDB matrix, pristine-install smoke,
  reproducible ZIP with byte-identical rebuild check, accessibility, release only of the tested
  ZIP; real Sandbox refund gate.

## M10 — v1.0 release closure (2026-09-30 – 2026-10-01)

- Plaid event streams, cursors, epochs, sync locks and refund identities scoped by environment +
  account; schema 3 with an audited, idempotent migration (ADR-0018);
- no same-order re-debit after an ACH return; `ReturnRetryPolicy` encodes Plaid's reprocessing
  rules (ADR-0019);
- Link customization required in Sandbox and Production; the Sandbox gate needs it (ADR-0020);
- maintenance stops when no work remains; settled refunds watched as long as the refunded debit
  (ADR-0021);
- WooCommerce 8.7 minimum after an HPOS refund persistence defect in 8.5/8.6 (ADR-0022);
- documentation version-controlled with a CI integrity gate; source audit; pinned Composer for the
  prefixed runtime; fresh-store package smoke per storage mode; browser E2E crash diagnostics;
- deferred events count as maintenance work; the Sandbox gate reports EXECUTED / NOT EXECUTED and a
  release fails unless it really ran; Plaid contracts re-verified against plaid.com on 2026-10-01.

## Post-v1

- Plaid-compliant reprocessing of R01/R09 returns through `/transfer/create` ("Retry 1/2");
- multiple simultaneously configured Plaid accounts;

- enhanced reconciliation;
- subscriptions/recurring supported architecture;
- additional rails;
- Europe;
- Platform model after Plaid alignment.
