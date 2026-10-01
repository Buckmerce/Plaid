<?php

declare(strict_types=1);

foreach (
    array(
        'ABSPATH' => __DIR__ . '/phpstan-wp-root/',
        'BUCKMERCE_PLAID_DIR' => dirname(__DIR__) . '/',
        'BUCKMERCE_PLAID_FILE' => '/tmp/buckmerce-plaid.php',
        'BUCKMERCE_PLAID_URL' => 'https://example.invalid/wp-content/plugins/buckmerce-plaid/',
        'BUCKMERCE_PLAID_VERSION' => '1.0.0',
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS' => 3600,
        'DAY_IN_SECONDS' => 86400,
        'ARRAY_A' => 'ARRAY_A',
    ) as $name => $value
) {
    if (! defined($name)) {
        define($name, $value);
    }
}
