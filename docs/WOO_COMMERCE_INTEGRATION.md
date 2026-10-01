# WooCommerce Integration

## 1. Gateway

`PayBridgeGateway` extends `WC_Payment_Gateway` and remains an adapter.

It handles:

- settings fields;
- title/description;
- availability;
- supported features;
- `process_payment()` coordination.

It does not contain Plaid wire-format code.

## 2. `process_payment()`

Expected logic:

```text
load order
→ validate payable + gateway + configuration
→ PaymentAttemptService::createOrReuse(order)
→ return WooCommerce success redirect to protected PayBridge/order-pay page
```

Do not mark the order paid here.

## 3. Classic Checkout

Classic checkout must use standard WooCommerce gateway APIs and reach the same backend service as Blocks.

## 4. Checkout Blocks

Use WooCommerce's payment method integration architecture.

Server integration exposes payment-method configuration. Client integration uses supported Blocks registry tooling.

Do not install/bundle WooCommerce packages incorrectly when they are expected to be externalized to registered WooCommerce script handles.

Do not manipulate Checkout DOM manually.

## 5. HPOS

Use `WC_Order` CRUD and WooCommerce order-query APIs.

Do not use direct `wp_posts`/`wp_postmeta` logic for payment/order behavior.

Declare HPOS compatibility only after code audit and automated tests.

## 6. Guest checkout authorization

A guest must be able to pay their order without logging in, but must not access another guest's order.

Use WooCommerce order-pay/order-key patterns plus session/context validation as appropriate.

Numeric order ID alone is not permission.

## 7. Order notes

Provider lifecycle notes must be private and concise.

Never include secrets or excessive provider payloads.

Examples:

```text
PayBridge: Plaid Transfer created. Transfer ID: ...
PayBridge: Transfer returned (R01 — Insufficient Funds).
```

## 8. Order meta box

Provide safe operational information and manual `Sync with Plaid` action protected by appropriate capability + nonce.

## 9. Stock and completion

Do not manually duplicate WooCommerce stock reduction/completion behavior. Project payment state into supported WooCommerce lifecycle methods/statuses.

## 10. Implementation notes (1.0)

- **Legal name:** Classic Checkout validates billing first and last name in `validate_fields()`
  before the order exists; Checkout Blocks (Store API passes only payment data to
  `validate_fields()`) and the payment page refuse in `process_payment()` / Link-token issuance
  with the same actionable message. No fallback name is ever sent (ADR-0013).
- **Availability:** new payments need enabled + complete configuration (credentials, environment,
  USD, HTTPS in Production and a Link customization in both environments). Blocks use the same rules
  (`get_payment_method_data()['available']`).
- **Refunds:** `supports` contains `refunds`; `can_refund_order()` shows "Refund … via PayBridge for
  Plaid" only for refundable Plaid payments; `process_refund()` is bound to the WooCommerce refund
  object captured from `woocommerce_create_refund` (ADR-0016). Full refunds get WooCommerce's
  Refunded status from WooCommerce itself.
- **Returned payments:** WooCommerce Failed + explicit PayBridge marker (ADR-0012), including an
  orders-list column for HPOS and legacy storage.
- **Repayment:** a failed or cancelled payment (no money moved) can be paid again through
  WooCommerce's order-pay link; PayBridge starts a new attempt and keeps the old one in the attempt
  history (ADR-0017). An order whose transfer was **returned** is never debited again by bank: Pay by
  Bank is not offered on its order-pay page, the form explains why, and the merchant collects the
  payment another way (`ReturnRetryPolicy`, ADR-0019).
- **Minimum WooCommerce 8.7:** earlier versions do not persist `refunded_payment` for HPOS refunds
  (ADR-0022).
- **Order panel:** see `docs/OBSERVABILITY_SUPPORT.md` §5; actions are `admin-post` requests with
  `manage_woocommerce` and a per-order nonce.
- **Disabled gateway:** hides Pay by Bank at checkout only; background maintenance continues
  (ADR-0014).
- **HPOS:** all order data through CRUD (`get_meta`, `update_meta_data`, `save`, verified
  read-back); no `get_post_meta`/`update_post_meta`/`wp_posts`/`wp_postmeta` in `src/` (PHPCS
  forbids the functions); order lookups by Plaid IDs use PayBridge's own indexed tables.
