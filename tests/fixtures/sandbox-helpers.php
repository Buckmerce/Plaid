<?php
/**
 * Plugin Name: PayBridge test — Sandbox checkout switch
 * Description: Disposable Sandbox sites only. Lets the Sandbox browser run switch the
 *              WooCommerce checkout page between the Classic shortcode page and the
 *              Checkout block page, so the genuine Plaid Transfer UI is driven from both.
 */

declare(strict_types=1);

if (! defined('PAYBRIDGE_PLAID_SANDBOX_TEST') || ! PAYBRIDGE_PLAID_SANDBOX_TEST) {
    return;
}

add_action('init', static function (): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- disposable test site only.
    if (! isset($_GET['pbfp_sandbox_checkout'])) {
        return;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- disposable test site only.
    $page = 'blocks' === sanitize_key(wp_unslash($_GET['pbfp_sandbox_checkout'])) ? (int) get_option('pbfp_sandbox_blocks_page') : (int) get_option('pbfp_sandbox_classic_page');
    update_option('woocommerce_checkout_page_id', $page);
    wp_send_json(array('ok' => $page > 0, 'checkout' => get_permalink($page)));
});
