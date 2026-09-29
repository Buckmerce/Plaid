<?php

/** Isolation for a throwaway integration/browser site, installed as an MU plugin. */

declare(strict_types=1);

if (! defined('PAYBRIDGE_PLAID_TEST_DATABASE') || true !== PAYBRIDGE_PLAID_TEST_DATABASE) {
    return;
}

// Test sites use self-signed/loopback hosts and run background work explicitly.
add_filter('pre_option_woocommerce_allow_tracking', static fn (): string => 'no');
add_filter('woocommerce_admin_disabled', '__return_true');
