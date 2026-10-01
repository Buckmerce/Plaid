# WooCommerce API — PayBridge Integration Contract

> This document describes which WooCommerce APIs, hooks and integration points
> PayBridge uses. It is not a substitute for official WooCommerce developer docs.
>
> **Official source:** <https://developer.woocommerce.com/>
> **Last verified:** 2026-09-30 (WooCommerce 8.5.2, 8.6.x, 8.7.0 and 11.1.2 source: `wc_create_refund()`,
> `wc_refund_payment()`, HPOS `OrdersTableRefundDataStore::update()` / `persist_updates()`,
> `WC_Checkout::validate_checkout()`, `WC_Shortcode_Checkout::order_pay()`, `StoreApi\Legacy`, gateway `admin_options()`)
> **Minimum supported:** WooCommerce 8.7 (ADR-0022)

---

## 1. Gateway registration

PayBridge registers as a WooCommerce payment gateway by extending `WC_Payment_Gateway`.

| Property | Value |
|---|---|
| Gateway ID | `paybridge_plaid` |
| Class | `PayBridge\Plaid\Gateway\PayBridgeGateway` |
| Registration hook | `woocommerce_payment_gateways` filter |
| Supports | `products`, `refunds` |
| `has_fields` | `false` (no inline checkout fields) |
| Settings option | `woocommerce_paybridge_plaid_settings` |

### Overridden methods

| Method | Purpose |
|---|---|
| `__construct()` | Defines form fields, loads settings |
| `init_form_fields()` | Declares gateway settings (credentials, ACH options, operations) |
| `is_available()` | Checks configuration completeness + runtime conditions; on the order-pay page also refuses an order whose transfer was returned (ADR-0019) |
| `validate_fields()` | Classic Checkout: requires billing first + last name before the order is created (`WC_Checkout::validate_checkout()` calls it; the Store API calls it with payment data only, so Blocks is validated in `process_payment()`) |
| `process_payment($order_id)` | Creates/reuses the attempt → redirects to the payment page |
| `can_refund_order($order)` | "Refund via Pay by Bank" only for refundable Plaid payments (local data, no Plaid call) |
| `process_refund($order_id, $amount, $reason)` | Plaid refund for the WooCommerce refund object just saved by `wc_create_refund()`; `WP_Error` makes WooCommerce delete that object and show the message (ADR-0016). On success WooCommerce then calls `$refund->set_refunded_payment(true); $refund->save()` — an update that HPOS persists only from WooCommerce 8.7.0 on (PR #44214; 8.5–8.6 drop it), hence the 8.7 minimum (ADR-0022) |
| `needs_setup()` | True without credentials (WooCommerce opens the settings instead of enabling) |
| `process_admin_options()` | Secret preservation, sanitizers (Client ID, customization, statement descriptor) |
| `generate_settings_html()` | Prepends the status panel (Ready/Disabled/Incomplete/Attention, Sandbox/Production badge, checklist, Test connection, webhook URL) as a table row |

**Source:** [`PayBridgeGateway`](../../src/Gateway/PayBridgeGateway.php)

---

## 2. HPOS (High-Performance Order Storage)

PayBridge declares HPOS compatibility and exclusively uses the WooCommerce order CRUD API.

### Compatibility declaration

```php
FeaturesUtil::declare_compatibility('custom_order_tables', PAYBRIDGE_PLAID_FILE, true);
```

**Hook:** `before_woocommerce_init`

### Order API usage

| Operation | WooCommerce API |
|---|---|
| Fetch order | `wc_get_order($order_id)` |
| Read meta | `$order->get_meta($key, true)` |
| Write meta | `$order->update_meta_data($key, $value)` |
| Delete meta | `$order->delete_meta_data($key)` |
| Save | `$order->save()` + read-back verification |
| Order number | `$order->get_order_number()` |
| Order key | `$order->get_order_key()` |
| Billing data | `$order->get_billing_first_name()`, `get_billing_last_name()`, `get_billing_email()`, `get_billing_company()` |
| Currency | `$order->get_currency()` |
| Total | `$order->get_total()`, `get_formatted_order_total()` |
| Status | `$order->update_status($status, $note)`, `$order->get_status()` |
| Payment method | `$order->get_payment_method()`, `set_payment_method()` |
| Customer ID | `$order->get_customer_id()` |
| Refund gateway flag | `WC_Order_Refund::get_refunded_payment()` (set by WooCommerce after a successful `process_refund()`) |
| Notes | `$order->add_order_note()` |
| Payment complete | `$order->payment_complete($transaction_id)` |
| Receipt URL | `$order->get_checkout_payment_url(true)` |
| Thank-you URL | `$order->get_checkout_order_received_url()` |
| Store currency | `get_woocommerce_currency()` |

**Invariant:** PayBridge never queries `wp_posts` / `wp_postmeta` for order business logic. All order lookups go through custom index tables (`wp_paybridge_plaid_payment_locks.transfer_intent_id`, `transfer_id`) instead of `meta_query` (which fails silently in legacy storage).

### Order meta keys

See `docs/DATA_MODEL.md` §9 for the complete list (all private `_pbfp_` keys, written with CRUD
and read back for critical values). Order lookups by Plaid identifiers use PayBridge's own
indexed tables, never `meta_query`.

---

## 3. Checkout Blocks integration

PayBridge declares Blocks compatibility and registers a payment method type.

### Compatibility declaration

```php
FeaturesUtil::declare_compatibility('cart_checkout_blocks', PAYBRIDGE_PLAID_FILE, true);
```

### Registration

**Hook:** `woocommerce_blocks_payment_method_type_registration`

```php
$registry->register(new PayBridgePaymentMethod());
```

`PayBridgePaymentMethod` extends `AbstractPaymentMethodType`:

| Method | Return |
|---|---|
| `$name` | `paybridge_plaid` |
| `is_active()` | Gateway configuration is complete |
| `get_payment_method_script_handles()` | Registers `paybridge-plaid-blocks` script |
| `get_payment_method_data()` | Title, description, icon, supports, availability |

**Script dependencies:** `wc-blocks-registry`, `wc-settings`, `wp-element`, `wp-html-entities`, `wp-i18n`

**Source:** [`PayBridgePaymentMethod`](../../src/Checkout/PayBridgePaymentMethod.php)

---

## 4. Classic Checkout integration

Classic Checkout uses the standard `WC_Payment_Gateway::process_payment()` flow.

`process_payment()` → reserve payment → redirect to order-pay receipt page →
render PayBridge payment page via `woocommerce_receipt_paybridge_plaid` action.

**Source:** [`PayBridgeGateway::process_payment()`](../../src/Gateway/PayBridgeGateway.php), [`PaymentPage`](../../src/Checkout/PaymentPage.php)

---

## 5. WooCommerce hooks used

### Actions (add_action)

| Hook | Handler | Purpose |
|---|---|---|
| `woocommerce_payment_gateways` | filter: adds `PayBridgeGateway` | Gateway registration |
| `woocommerce_update_options_payment_gateways_paybridge_plaid` | `process_admin_options()` | Save settings |
| `woocommerce_receipt_paybridge_plaid` | `PaymentPage::render()` | Payment page UI (shows why a returned order cannot be paid by bank) |
| `before_woocommerce_pay_form` | `PaymentPage::returned_payment_notice()` | Order-pay form: explains that Pay by Bank is not offered for a returned bank payment (ADR-0019) |
| `woocommerce_blocks_payment_method_type_registration` | `Plugin::register_blocks()` | Blocks registration |

### Filters (add_filter)

| Hook | Handler | Purpose |
|---|---|---|
| `woocommerce_cancel_unpaid_order` | `PaymentPage::keep_order_during_authorization()` | Prevent auto-cancellation during active payment |

| `woocommerce_create_refund` | `WooRefundContext::capture()` | Binds the refund being saved to the Plaid refund (idempotency key) |
| `manage_woocommerce_page_wc-orders_columns` / `manage_edit-shop_order_columns` (+ custom column actions) | `OrderListColumn` | "Pay by Bank" column: returned, review, in-flight badges (HPOS and legacy) |
| `woocommerce_admin_order_data_after_order_details` | `OrderMetaBox::render()` | PayBridge panel |

### Actions fired by PayBridge

| Hook | When |
|---|---|
| `paybridge_plaid_webhook_processed` | After a verified `TRANSFER_EVENTS_UPDATE` webhook is enqueued |
| `paybridge_plaid_transfer_intent_created` | Transfer Intent stored |
| `paybridge_plaid_payment_state_changed` | Any applied payment transition (order, from, to) |
| `paybridge_plaid_payment_confirmed`, `_failed`, `_cancelled`, `_returned`, `_manual_review` | Projection of the corresponding state |
| `paybridge_plaid_refund_created`, `_failed`, `_returned`, `_cancelled` | Refund lifecycle |

### Filters offered by PayBridge

| Filter | Purpose |
|---|---|
| `paybridge_plaid_alert_recipient` | Recipients of payment/refund alert emails (default: site admin email) |

---

## 6. Order status transitions

| From | To | Trigger |
|---|---|---|
| _new_ → | `pending` | WooCommerce creates the order at checkout |
| `pending`/`failed` → | `on-hold` | Transfer created (intent succeeded) |
| `on-hold` → | `processing`/`completed` | `payment_complete()` at the configured confirmation state (WooCommerce decides) |
| `on-hold`/`pending` → | `failed` | Transfer failed |
| `on-hold`/`pending` → | `cancelled` | Transfer cancelled (incl. merchant "Cancel bank payment") |
| any unpaid → | `on-hold` | Manual review |
| paid → | `failed` | ACH return (ADR-0012; history kept, explicit badge/alert). The order still "needs payment" in WooCommerce terms, but PayBridge never debits it again (ADR-0019): Pay by Bank is hidden on its order-pay page and the merchant collects another way |
| any → | `refunded` | Full refund (WooCommerce's own rule in `wc_create_refund()`) |
| `on-hold` → | `pending` | Merchant releases a manual review without a transfer ("Let the customer pay again") |

PayBridge registers no custom order status (ADR-0012 explains why a `wc-pbfp-returned` status
was rejected).

---

## 7. WooCommerce session handling

WooCommerce does not initialise its session for REST API requests. PayBridge manually
calls `WC()->initialize_session()` when needed for guest payment access validation.

**Source:** [`PaymentAccess::session()`](../../src/Checkout/PaymentAccess.php)

---

## 8. Gateway availability conditions

Checked by `GatewayAvailability::problems()`:

| Condition | Requirement |
|---|---|
| Plugin enabled | Gateway `enabled` = `yes` (only for NEW payments; maintenance is independent, ADR-0014) |
| Environment | `sandbox` or `production` |
| Credentials | `client_id` and `secret` are non-empty |
| Link customization | Required in Sandbox and Production (ADR-0013, ADR-0020) |
| Currency | Order/store currency is `USD` |
| SSL | Production requires HTTPS (`wc_site_is_https()`) |

**Source:** [`GatewayAvailability`](../../src/Gateway/GatewayAvailability.php)

---

## 9. Admin settings assets

| Asset | Handle | Hook |
|---|---|---|
| CSS | `paybridge-plaid-admin` | `admin_enqueue_scripts` (gateway settings, orders list, order edit, diagnostics) |
| JS | `paybridge-plaid-admin` | `admin_enqueue_scripts` (only on `wc-settings` page) |

---

## 10. Freshness protocol

Before modifying WooCommerce integration code:

1. Read this document
2. Read official WooCommerce developer docs for the API you're touching
3. Verify HPOS compatibility for any order data access
4. Verify Blocks compatibility for any checkout changes
5. Update this document if the external contract changed
6. Run both Classic and Blocks checkout tests after changes
