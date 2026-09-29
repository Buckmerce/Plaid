<?php

declare(strict_types=1);

/**
 * Unit-test bootstrap: pure PHP only. Minimal WordPress function doubles are
 * defined for code paths that translate strings or read options; anything
 * that needs a real database or WooCommerce runs in tests/Integration.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

foreach (
    array(
        'ABSPATH' => __DIR__ . '/phpstan-wp-root/',
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS' => 3600,
        'DAY_IN_SECONDS' => 86400,
        'PAYBRIDGE_PLAID_VERSION' => '0.1.0',
    ) as $name => $value
) {
    if (! defined($name)) {
        define($name, $value);
    }
}

/** In-memory option/transient store shared by the doubles below. */
final class PayBridgeTestStore
{
    /** @var array<string, mixed> */
    public static array $options = array();
    /** @var array<string, mixed> */
    public static array $transients = array();

    public static function reset(): void
    {
        self::$options = array();
        self::$transients = array();
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES);
    }
}
if (! function_exists('get_option')) {
    function get_option(string $name, $default = false)
    {
        return PayBridgeTestStore::$options[$name] ?? $default;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        PayBridgeTestStore::$options[$name] = $value;
        return true;
    }
}
if (! function_exists('add_option')) {
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = null): bool
    {
        if (array_key_exists($name, PayBridgeTestStore::$options)) {
            return false;
        }
        PayBridgeTestStore::$options[$name] = $value;
        return true;
    }
}
if (! function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return PayBridgeTestStore::$transients[$key] ?? false;
    }
}
if (! function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        PayBridgeTestStore::$transients[$key] = $value;
        return true;
    }
}
if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, $value)
    {
        return $value;
    }
}
if (! function_exists('wp_json_encode')) {
    function wp_json_encode($value, int $flags = 0)
    {
        return json_encode($value, $flags);
    }
}
if (! function_exists('is_email')) {
    function is_email(string $email)
    {
        return false !== filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
}
if (! function_exists('wc_site_is_https')) {
    function wc_site_is_https(): bool
    {
        return true;
    }
}
