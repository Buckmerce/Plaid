<?php

declare(strict_types=1);

/**
 * Unit-test bootstrap: pure PHP only. Minimal WordPress function doubles are
 * defined for code paths that translate strings or read options; anything
 * that needs a real database or WooCommerce runs in tests/Integration.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';
// The prefixed runtime libraries (Buckmerce\Plaid\Vendor\...) are loaded here, the way the plugin's
// main file loads them. They are deliberately not an autoload-dev "files" entry of composer.json:
// vendor/autoload.php would then require vendor-prefixed/ before Strauss has generated it, and a
// `composer install` on a tree without vendor-prefixed/ could never regenerate it.
require_once dirname(__DIR__) . '/vendor-prefixed/autoload.php';

foreach (
    array(
        'ABSPATH' => __DIR__ . '/phpstan-wp-root/',
        'MINUTE_IN_SECONDS' => 60,
        'HOUR_IN_SECONDS' => 3600,
        'DAY_IN_SECONDS' => 86400,
        'BUCKMERCE_PLAID_VERSION' => '1.0.0',
    ) as $name => $value
) {
    if (! defined($name)) {
        define($name, $value);
    }
}

/** In-memory option/transient store shared by the doubles below. */
final class BuckmerceTestStore
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
        return BuckmerceTestStore::$options[$name] ?? $default;
    }
}
if (! function_exists('update_option')) {
    function update_option(string $name, $value, $autoload = null): bool
    {
        BuckmerceTestStore::$options[$name] = $value;
        return true;
    }
}
if (! function_exists('add_option')) {
    function add_option(string $name, $value = '', string $deprecated = '', $autoload = null): bool
    {
        if (array_key_exists($name, BuckmerceTestStore::$options)) {
            return false;
        }
        BuckmerceTestStore::$options[$name] = $value;
        return true;
    }
}
if (! function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return BuckmerceTestStore::$transients[$key] ?? false;
    }
}
if (! function_exists('set_transient')) {
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        BuckmerceTestStore::$transients[$key] = $value;
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
if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://store.example.test' . $path;
    }
}
if (! function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'unit-test-salt-' . $scheme;
    }
}
if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset(BuckmerceTestStore::$options[$name]);
        return true;
    }
}

if (! class_exists('WC_Order')) {
    /**
     * Minimal order double for pure unit tests of policies that read order meta.
     * Integration tests (tests/Integration) always use real WooCommerce orders.
     */
    class WC_Order
    {
        /** @param array<string, mixed> $meta */
        public function __construct(private array $meta = array(), private string $payment_method = 'buckmerce_plaid', private int $id = 1001)
        {
        }

        public function get_id(): int
        {
            return $this->id;
        }

        public function get_payment_method(): string
        {
            return $this->payment_method;
        }

        /** @return mixed */
        public function get_meta(string $key, bool $single = true)
        {
            return $this->meta[$key] ?? '';
        }

        public function update_meta_data(string $key, mixed $value): void
        {
            $this->meta[$key] = $value;
        }

        public function delete_meta_data(string $key): void
        {
            unset($this->meta[$key]);
        }

        public function get_date_paid(string $context = 'view'): ?\DateTimeInterface
        {
            return null;
        }
    }
}
