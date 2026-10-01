# ADR-0022: Minimum WooCommerce version is 8.7

**Status:** Accepted (1.0.0). Replaces the WooCommerce 8.5 minimum.

## Context

The minimum-version CI jobs (WordPress 6.6, WooCommerce 8.5.2, MySQL 8.0 and MariaDB 10.11)
failed: after a successful Plaid refund, `WC_Order_Refund::get_refunded_payment()` was false under
HPOS. Evidence from the WooCommerce sources:

- `wc_create_refund()` saves the refund, calls the gateway (`process_refund()`), then calls
  `$refund->set_refunded_payment(true); $refund->save();` — an **update** of an existing refund.
- In WooCommerce 8.5.x and 8.6.x, `OrdersTableRefundDataStore::update()` calls only
  `persist_updates()`, and `OrdersTableDataStore::persist_updates()` does **not** call
  `update_order_meta()`. The refund's `_refunded_payment` prop is therefore written only when the
  refund is created (as false) and never updated under HPOS. Verified in 8.5.2, 8.5.5, 8.6.0 and
  8.6.4.
- WooCommerce 8.7.0 adds `$this->update_order_meta( $order );` to `persist_updates()`; its
  changelog: "Fix - Fix refund props not updating properly with HPOS
  [#44214](https://github.com/woocommerce/woocommerce/pull/44214)".
- Legacy (posts) storage is not affected.

A PayBridge workaround would have to write the refund's internal meta through
`Automattic\WooCommerce\Internal\DataStores\Orders\*` APIs (marked internal; forbidden by
AGENTS.md §7) or query WooCommerce tables directly (forbidden), or set `refunded_payment` before
the Plaid refund exists (wrong when the Plaid refund fails).

## Decision

Raise the minimum to **WooCommerce 8.7** (`WC requires at least: 8.7`,
`Support\Requirements::MIN_WOOCOMMERCE`). The minimum CI rows test WooCommerce 8.7.0 on
WordPress 6.6 / PHP 8.1 with MySQL 8.0 and MariaDB 10.11, HPOS on and off, and the pristine package
smoke runs 8.7.0 too. On an older WooCommerce, PayBridge does not load and shows an admin notice.

## Consequences

- Native refunds are recorded accurately as "refunded through the gateway" on every supported
  WooCommerce version and storage mode.
- Stores on WooCommerce 8.5/8.6 must update WooCommerce before installing PayBridge 1.0.
- Tests: `tests/Integration/wp-cli-refunds.php` ("WooCommerce records the refund as paid by the
  gateway") in the minimum and latest CI rows.
