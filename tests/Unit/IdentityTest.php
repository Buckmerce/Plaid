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
 * former working name or of the slug used before the WordPress.org identity — fails the unit
 * suite. The repository-wide scan for former identifiers is scripts/check-identity.sh; the same
 * checks on the built ZIP are scripts/verify-package.sh.
 */
final class IdentityTest extends TestCase
{
    private const SLUG = 'buckmerce-plaid';
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
        // Position of the text-domain argument of every WordPress translation function.
        $domain_argument = array(
            '__' => 1, '_e' => 1, 'esc_html__' => 1, 'esc_html_e' => 1, 'esc_attr__' => 1, 'esc_attr_e' => 1, 'translate' => 1,
            '_x' => 2, '_ex' => 2, 'esc_html_x' => 2, 'esc_attr_x' => 2, '_n_noop' => 2,
            '_n' => 3, '_nx_noop' => 3,
            '_nx' => 4,
            'load_plugin_textdomain' => 0, 'wp_set_script_translations' => 1,
        );
        $calls = 0;
        foreach (array_merge(self::php_files('src'), array(self::root() . '/' . self::SLUG . '.php', self::root() . '/uninstall.php')) as $file) {
            $relative = substr($file, strlen(self::root()) + 1);
            $tokens = token_get_all((string) file_get_contents($file));
            foreach ($tokens as $index => $token) {
                if (! is_array($token) || T_STRING !== $token[0] || ! isset($domain_argument[$token[1]])) {
                    continue;
                }
                $arguments = self::call_arguments($tokens, $index);
                if (null === $arguments) {
                    continue;
                }
                ++$calls;
                self::assertSame(
                    "'" . self::SLUG . "'",
                    $arguments[$domain_argument[$token[1]]] ?? null,
                    sprintf('%s:%d: %s() must use the text domain %s as a literal.', $relative, $token[2], $token[1], self::SLUG)
                );
            }
        }
        self::assertGreaterThan(400, $calls, 'The translation calls of the runtime code were found.');
        self::assertStringContainsString("i18n.__( text, '" . self::SLUG . "' )", self::read('resources/ts/blocks.ts'), 'The Checkout block script translates with the plugin text domain.');
        self::assertStringContainsString("bmfp_i18n_domain=" . self::SLUG . "\n", self::read('scripts/lib/i18n.sh'), 'The translation tooling builds the plugin text domain.');
    }

    public function test_translation_files_are_named_after_the_text_domain(): void
    {
        $files = array_map('basename', glob(self::root() . '/languages/*') ?: array());
        self::assertContains(self::SLUG . '.pot', $files, 'Translation template: languages/' . self::SLUG . '.pot.');
        foreach ($files as $file) {
            self::assertMatchesRegularExpression(
                '/^' . preg_quote(self::SLUG, '/') . '(\.pot|-[a-z]{2,3}(_[A-Z]{2})?(\.po|\.mo|\.l10n\.php|-[a-f0-9]{32}\.json))$/',
                $file,
                'WordPress only loads translation files named <text domain>-<locale>.'
            );
        }
        foreach (array('.pot', '-ru_RU.po') as $suffix) {
            $catalogue = self::read('languages/' . self::SLUG . $suffix);
            self::assertStringContainsString('"X-Domain: ' . self::SLUG . '\n"', $catalogue);
            self::assertStringContainsString('"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/' . self::SLUG . '\n"', $catalogue);
            self::assertStringContainsString('#: ' . self::SLUG . ".php\n", $catalogue, 'The plugin header strings come from the main file.');
        }
        foreach (array('.mo', '.l10n.php') as $suffix) {
            self::assertFileExists(self::root() . '/languages/' . self::SLUG . '-ru_RU' . $suffix, 'The bundled ru_RU translation is compiled.');
        }
    }

    public function test_package_release_and_environment_names(): void
    {
        self::assertSame(self::SLUG, json_decode(self::read('package.json'), true)['name']);
        self::assertSame(self::SLUG, json_decode(self::read('package-lock.json'), true)['name']);
        $package = self::read('scripts/package.sh');
        self::assertStringContainsString('SLUG="' . self::SLUG . '"', $package);
        self::assertStringContainsString('MAIN_FILE="$BASE_DIR/' . self::SLUG . '.php"', $package);
        self::assertStringContainsString('ZIP="$DIST_DIR/$SLUG-$VERSION.zip"', $package, 'Release ZIP: dist/buckmerce-plaid-<version>.zip.');
        self::assertStringContainsString('PLUGIN_DIR="$STAGE_DIR/$SLUG"', $package, 'ZIP root directory: buckmerce-plaid/.');
        $verify = self::read('scripts/verify-package.sh');
        self::assertStringContainsString("slug=" . self::SLUG . "\n", $verify);
        self::assertStringContainsString('zip=${1:-"$base_dir/dist/' . self::SLUG . '-$version.zip"}', $verify);
        foreach (array('test-package-smoke.sh', 'test-integration.sh', 'test-plugin-check.sh', 'test-browser-e2e.sh', 'test-sandbox-e2e.sh') as $script) {
            self::assertStringContainsString('"$base_dir/dist/' . self::SLUG . '-$plugin_version.zip"', self::read('scripts/' . $script), $script . ' tests the release ZIP.');
        }
        self::assertStringContainsString('plugin check ' . self::SLUG . ' ', self::read('scripts/test-plugin-check.sh'), 'Plugin Check scans the installed release ZIP by its slug.');

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

    public function test_release_manifest_is_an_allowlist_of_runtime_files(): void
    {
        $manifest = self::read('scripts/lib/package-manifest.sh');
        preg_match('/^bmfp_release_top_level=\((.*?)^\)/ms', $manifest, $block);
        preg_match_all('/^\s+([A-Za-z0-9._-]+)/m', $block[1] ?? '', $entries);
        $top_level = $entries[1];
        sort($top_level);
        self::assertSame(
            array('LICENSE', 'assets', self::SLUG . '.php', 'composer.json', 'languages', 'readme.txt', 'src', 'uninstall.php', 'vendor-prefixed'),
            $top_level,
            'The WordPress.org package has exactly these top-level entries; composer.json is shipped, vendor/ is not.'
        );
        preg_match('/^bmfp_release_allowlist=\((.*?)^\)/ms', $manifest, $block);
        preg_match_all("/^\s+'([^']+)'/m", $block[1] ?? '', $patterns);
        self::assertNotEmpty($patterns[1]);
        $allowed = static function (string $path) use ($patterns): bool {
            foreach ($patterns[1] as $pattern) {
                if (1 === preg_match('~' . $pattern . '~', $path)) {
                    return true;
                }
            }
            return false;
        };
        // Every runtime file of the repository is covered …
        $runtime = array(self::SLUG . '.php', 'readme.txt', 'LICENSE', 'composer.json', 'uninstall.php', 'vendor-prefixed/autoload.php', 'vendor-prefixed/firebase/php-jwt/LICENSE', 'vendor-prefixed/firebase/php-jwt/src/JWT.php', 'vendor-prefixed/composer/installed.php');
        foreach (array_merge(self::php_files('src'), glob(self::root() . '/languages/*') ?: array()) as $file) {
            $runtime[] = substr($file, strlen(self::root()) + 1);
        }
        foreach ($runtime as $path) {
            self::assertTrue($allowed($path), 'Runtime file missing from the release allowlist: ' . $path);
        }
        // … and nothing of the development project is.
        $development = array(
            '.env', '.env.example', '.gitignore', '.phpunit.result.cache', '.github/workflows/quality.yml', '.idea/workspace.xml',
            'AGENTS.md', 'CLAUDE.md', 'CHANGELOG.md', 'README.md', 'composer.lock', 'package.json', 'package-lock.json', 'tsconfig.json',
            'phpunit.xml.dist', 'phpstan.neon', 'phpcs.xml.dist', 'tests/Unit/IdentityTest.php', 'docs/SECURITY.md', 'scripts/package.sh',
            'resources/ts/blocks.ts', 'node_modules/parcel/package.json', 'vendor/autoload.php', 'vendor/firebase/php-jwt/src/JWT.php',
            'dist/' . self::SLUG . '-1.0.0.zip', self::SLUG . '.zip', 'output/report.json', 'src/Plugin.php.orig', 'src/.gitkeep', 'src/debug.log',
            'assets/build/blocks.js.map', 'assets/build/extra.js', 'languages/other-domain-ru_RU.mo', 'languages/.gitkeep', 'dump.sql',
            'vendor-prefixed/firebase/php-jwt/README.md', 'vendor-prefixed/firebase/php-jwt/composer.json', 'vendor-prefixed/firebase/php-jwt/tests/JWTTest.php',
        );
        foreach ($development as $path) {
            self::assertFalse($allowed($path), 'Development file accepted by the release allowlist: ' . $path);
        }
        $package = self::read('scripts/package.sh');
        self::assertStringContainsString('. "$BASE_DIR/scripts/lib/package-manifest.sh"', $package);
        self::assertStringNotContainsString('cp -a', $package, 'The package is staged file by file from the allowlist, never by copying directories and pruning.');
        self::assertStringNotContainsString('cp -R', $package);
        self::assertStringContainsString('. "$base_dir/scripts/lib/package-manifest.sh"', self::read('scripts/verify-package.sh'));
    }

    public function test_distributable_files_hold_no_superseded_slug(): void
    {
        // The slug used before the WordPress.org identity; assembled so that this file does not
        // contain it. Everything the release ZIP is built from is scanned: names and content,
        // compiled translations included. Documentation that is not shipped is not.
        $superseded = 'buckmerce-' . 'for-plaid';
        $files = array();
        foreach (array(self::SLUG . '.php', 'uninstall.php', 'readme.txt', 'composer.json', 'LICENSE') as $relative) {
            $files[] = self::root() . '/' . $relative;
        }
        foreach (array('src', 'languages', 'vendor-prefixed', 'resources', 'assets') as $directory) {
            if (is_dir(self::root() . '/' . $directory)) {
                $files = array_merge($files, self::files($directory));
            }
        }
        self::assertGreaterThan(130, count($files));
        $hits = array();
        foreach ($files as $file) {
            $relative = substr($file, strlen(self::root()) + 1);
            $occurrences = substr_count((string) file_get_contents($file), $superseded) + substr_count($relative, $superseded);
            if ($occurrences > 0) {
                $hits[] = $relative . ' (' . $occurrences . ')';
            }
        }
        self::assertSame(array(), $hits, 'Distributable files still carry the superseded slug; the only slug is ' . self::SLUG . '.');
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
        return array_values(array_filter(self::files($directory), static fn (string $file): bool => str_ends_with($file, '.php')));
    }

    /** @return list<string> */
    private static function files(string $directory): array
    {
        $files = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    /**
     * The top-level arguments of the call that starts at $tokens[$index], each as trimmed source
     * text; null when the token is not a function call (a method definition, a property, …).
     *
     * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>|null
     */
    private static function call_arguments(array $tokens, int $index): ?array
    {
        $previous = $index - 1;
        while ($previous >= 0 && is_array($tokens[$previous]) && T_WHITESPACE === $tokens[$previous][0]) {
            --$previous;
        }
        if ($previous >= 0 && is_array($tokens[$previous]) && in_array($tokens[$previous][0], array(T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION), true)) {
            return null;
        }
        $next = $index + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && T_WHITESPACE === $tokens[$next][0]) {
            ++$next;
        }
        if ('(' !== ($tokens[$next] ?? null)) {
            return null;
        }
        $arguments = array();
        $current = '';
        $depth = 0;
        for ($position = $next + 1; isset($tokens[$position]); ++$position) {
            $token = $tokens[$position];
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, array('(', '[', '{'), true)) {
                ++$depth;
            } elseif (in_array($text, array(')', ']', '}'), true)) {
                if (0 === $depth) {
                    if ('' !== trim($current)) {
                        $arguments[] = trim($current);
                    }
                    return $arguments;
                }
                --$depth;
            } elseif (',' === $text && 0 === $depth) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $text;
        }
        return null;
    }
}
