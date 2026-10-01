<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Admin\ConnectionTester;
use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\ReconciliationService;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\OrderMeta;
use Buckmerce\Plaid\Payment\PaymentAlerts;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Payment\PaymentSnapshot;
use Buckmerce\Plaid\Persistence\EventCursor;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentEpoch;
use Buckmerce\Plaid\Plaid\TransferIntent\TransferIntentRequest;
use Buckmerce\Plaid\REST\RestRoutes;
use Buckmerce\Plaid\REST\WebhookController;
use Buckmerce\Plaid\Settings\AccountChangeGuard;
use Buckmerce\Plaid\Settings\Settings;
use PHPUnit\Framework\TestCase;

/**
 * The canonical identity of the plugin (AGENTS.md §1). Every identifier a store, Plaid, WordPress
 * or a release consumer can observe is asserted here, so an accidental rename — or the return of a
 * former working name — fails the unit suite. The repository-wide scan for former identifiers is
 * scripts/check-identity.sh.
 */
final class IdentityTest extends TestCase
{
    private const SLUG = 'buckmerce-for-plaid';
    private const PLUGIN_NAME = 'Buckmerce – Bank Payments via Plaid for WooCommerce';

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $content = file_get_contents(self::root() . '/' . $relative);
        self::assertIsString($content, $relative . ' is readable.');
        return $content;
    }

    public function test_main_file_and_plugin_header(): void
    {
        $root_php = array_map('basename', glob(self::root() . '/*.php') ?: array());
        sort($root_php);
        self::assertSame(array(self::SLUG . '.php', 'uninstall.php'), $root_php, 'The main file is named after the slug.');
        $main = self::read(self::SLUG . '.php');
        self::assertStringContainsString(' * Plugin Name: ' . self::PLUGIN_NAME . "\n", $main);
        self::assertStringContainsString(' * Description: Secure Pay by Bank payments for WooCommerce using Plaid.' . "\n", $main);
        self::assertStringContainsString(' * Text Domain: ' . self::SLUG . "\n", $main);
        self::assertStringContainsString(' * @package Buckmerce\\Plaid' . "\n", $main);
        foreach (array('VERSION', 'FILE', 'DIR', 'URL') as $constant) {
            self::assertStringContainsString("define( 'BUCKMERCE_PLAID_" . $constant . "'", $main);
        }
        preg_match_all("/define\(\s*'([A-Z_]+)'/", $main, $defined);
        foreach ($defined[1] as $constant) {
            self::assertStringStartsWith('BUCKMERCE_PLAID_', $constant, 'Every bootstrap constant carries the plugin prefix.');
        }
    }

    public function test_runtime_identifiers(): void
    {
        self::assertSame('buckmerce_plaid', Settings::GATEWAY_ID);
        self::assertSame('woocommerce_buckmerce_plaid_settings', Settings::OPTION);
        self::assertSame(self::SLUG . '/v1', RestRoutes::NAMESPACE);
        self::assertSame(self::SLUG, Logger::SOURCE);
        self::assertSame(self::SLUG, Scheduler::GROUP);
        self::assertSame(
            array('buckmerce_plaid_transfer_event_sync', 'buckmerce_plaid_reconcile', 'buckmerce_plaid_reconcile_continue'),
            Scheduler::hooks()
        );
        self::assertStringContainsString("WP_CLI::add_command('buckmerce-plaid',", self::read('src/Plugin.php'), 'WP-CLI namespace: wp buckmerce-plaid.');
    }

    public function test_database_tables(): void
    {
        $previous = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class () {
            public string $prefix = 'wp_';
        };
        try {
            self::assertSame(
                array('wp_buckmerce_plaid_events', 'wp_buckmerce_plaid_payment_locks', 'wp_buckmerce_plaid_refunds'),
                Installer::tables()
            );
        } finally {
            $GLOBALS['wpdb'] = $previous;
        }
    }

    public function test_options_hooks_and_transients_use_the_plugin_prefixes(): void
    {
        $options = array(
            Installer::OPTION,
            EventCursor::OPTION_PREFIX,
            PaymentEpoch::OPTION_PREFIX,
            EventSyncService::HEALTH_OPTION_PREFIX,
            ReconciliationService::LAST_RUN_OPTION,
            ReconciliationService::LAST_ERROR_OPTION,
            ConnectionTester::LAST_RESULT_OPTION,
            PaymentAlerts::OPTION,
            WebhookController::LAST_WEBHOOK_OPTION,
            WebhookController::LAST_REJECTION_OPTION,
            PaymentAttemptService::LAST_LINK_ERROR_OPTION,
        );
        foreach ($options as $option) {
            self::assertStringStartsWith('buckmerce_plaid_', $option);
        }
        foreach (array(ConnectionTester::TRANSIENT_PREFIX, AccountChangeGuard::NOTICE_TRANSIENT) as $transient) {
            self::assertStringStartsWith('bmfp_', $transient);
        }
        // Every hook the plugin fires or filters it offers is buckmerce_plaid_*.
        $source = '';
        foreach (self::php_files('src') as $file) {
            $source .= (string) file_get_contents($file);
        }
        preg_match_all("/(?:do_action|apply_filters)\(\s*'([a-z0-9_]+)'/", $source, $fired);
        self::assertNotEmpty($fired[1]);
        foreach (array_unique($fired[1]) as $hook) {
            self::assertStringStartsWith('buckmerce_plaid_', $hook, 'Plugin-owned hook: ' . $hook);
        }
        foreach (array('payment_state_changed', 'payment_confirmed', 'payment_failed', 'payment_cancelled', 'payment_returned', 'payment_manual_review') as $suffix) {
            self::assertStringContainsString('buckmerce_plaid_' . $suffix, $source);
        }
        // Transient and lock names built in the code start with the short prefix.
        preg_match_all("/_transient\(\s*'([a-z0-9_]+)'/", $source, $transients);
        foreach (array_unique($transients[1]) as $transient) {
            self::assertStringStartsWith('bmfp_', $transient, 'Plugin-owned transient: ' . $transient);
        }
    }

    public function test_order_meta_prefix(): void
    {
        $keys = array_filter(( new \ReflectionClass(OrderMeta::class) )->getConstants(), 'is_string');
        self::assertGreaterThan(20, count($keys));
        foreach ($keys as $name => $key) {
            self::assertStringStartsWith('_bmfp_', $key, 'OrderMeta::' . $name);
        }
    }

    public function test_plaid_metadata_keys(): void
    {
        $snapshot = PaymentSnapshot::create(77, '11.11', 'USD', 'sandbox');
        $request = TransferIntentRequest::build($snapshot, 'PAYMENT', array('legal_name' => 'Anne Charleston'), 'same-day-ach', '', 'sitemarker0123456');
        self::assertSame(array('bmfp_order_id', 'bmfp_attempt_id', 'bmfp_environment', 'bmfp_site'), array_keys($request['metadata']));
    }

    public function test_namespace_autoloading_and_prefixed_dependencies(): void
    {
        $composer = json_decode(self::read('composer.json'), true);
        self::assertSame('al5dy/' . self::SLUG, $composer['name']);
        self::assertSame(array('Buckmerce\\Plaid\\' => 'src/'), $composer['autoload']['psr-4']);
        self::assertSame('Buckmerce\\Plaid\\Vendor\\', $composer['extra']['strauss']['namespace_prefix']);
        self::assertSame('Buckmerce_Plaid_Vendor_', $composer['extra']['strauss']['classmap_prefix']);
        self::assertSame('BUCKMERCE_PLAID_VENDOR_', $composer['extra']['strauss']['constant_prefix']);
        self::assertTrue(class_exists('Buckmerce\\Plaid\\Vendor\\Firebase\\JWT\\JWT'), 'The bundled JWT library lives in the plugin vendor namespace.');
        self::assertFalse(class_exists('Firebase\\JWT\\JWT', false), 'The unprefixed library is never loaded by the plugin.');
        foreach (self::php_files('src') as $file) {
            preg_match('/^namespace ([^;]+);/m', (string) file_get_contents($file), $namespace);
            self::assertStringStartsWith('Buckmerce\\Plaid', $namespace[1] ?? '', substr($file, strlen(self::root()) + 1));
        }
    }

    public function test_text_domain(): void
    {
        self::assertFileExists(self::root() . '/languages/' . self::SLUG . '.pot');
        $domains = array();
        foreach (array_merge(self::php_files('src'), array(self::root() . '/' . self::SLUG . '.php')) as $file) {
            preg_match_all("/(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(.*?,\s*'([a-z0-9-]+)'\s*\)/s", (string) file_get_contents($file), $found);
            foreach ($found[1] as $domain) {
                $domains[$domain] = true;
            }
        }
        self::assertSame(array(self::SLUG), array_keys($domains), 'Every translation call uses the plugin text domain.');
    }

    public function test_package_release_and_environment_names(): void
    {
        self::assertSame(self::SLUG, json_decode(self::read('package.json'), true)['name']);
        self::assertSame(self::SLUG, json_decode(self::read('package-lock.json'), true)['name']);
        $package = self::read('scripts/package.sh');
        self::assertStringContainsString('SLUG="' . self::SLUG . '"', $package);
        self::assertStringContainsString('ZIP="$DIST_DIR/$SLUG-$VERSION.zip"', $package, 'Release ZIP: dist/buckmerce-for-plaid-<version>.zip.');
        self::assertStringContainsString('PLUGIN_DIR="$STAGE_DIR/$SLUG"', $package, 'ZIP root directory: buckmerce-for-plaid/.');

        // Every repository-owned environment variable is BUCKMERCE_PLAID_*.
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', self::read('.env.example'), $example);
        self::assertNotEmpty($example[1]);
        foreach ($example[1] as $variable) {
            self::assertStringStartsWith('BUCKMERCE_PLAID_', $variable, '.env.example: ' . $variable);
        }
        $tooling = self::read('.github/workflows/quality.yml');
        foreach (glob(self::root() . '/scripts/{*.sh,lib/*.sh}', GLOB_BRACE) ?: array() as $script) {
            $tooling .= (string) file_get_contents($script);
        }
        preg_match_all('/\b([A-Z][A-Z0-9]*_PLAID_[A-Z0-9_]+)\b/', $tooling, $variables);
        self::assertNotEmpty($variables[1]);
        foreach (array_unique($variables[1]) as $variable) {
            self::assertStringStartsWith('BUCKMERCE_PLAID_', $variable, 'Tooling variable: ' . $variable);
        }
        // Disposable databases are recognisable and guarded by name.
        foreach (array('test-integration.sh' => 'buckmerce_test_', 'test-browser-e2e.sh' => 'buckmerce_browser_', 'test-plugin-check.sh' => 'buckmerce_check_', 'test-sandbox-e2e.sh' => 'buckmerce_sandbox_') as $script => $prefix) {
            self::assertStringContainsString('=~ ^' . $prefix . '[a-z0-9]+$', self::read('scripts/' . $script), $script . ' only drops its own disposable database.');
        }
    }

    public function test_runtime_code_holds_no_former_identity(): void
    {
        // Assembled so that this file does not contain the former names either.
        $pattern = '/' . 'pay' . '[ _-]?' . 'bridge' . '|' . 'pb' . 'fp' . '/i';
        $files = array_merge(self::php_files('src'), array(self::root() . '/' . self::SLUG . '.php', self::root() . '/uninstall.php'));
        foreach ($files as $file) {
            // Relative path: the checkout directory may have any name.
            $relative = substr($file, strlen(self::root()) + 1);
            self::assertSame(0, preg_match($pattern, (string) file_get_contents($file) . ' ' . $relative), 'Former identity in ' . $relative);
        }
    }

    /** @return list<string> */
    private static function php_files(string $directory): array
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }
}
