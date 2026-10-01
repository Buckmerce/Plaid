<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Background\EventSyncService;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\Settings\Settings;
use PayBridge\Plaid\Support\Money;

/**
 * One merchant-facing verdict about the configuration and the background processing:
 * Ready, Disabled, Incomplete or Attention required, plus a checklist. Local checks only —
 * nothing here calls Plaid, so it can render on every admin page view.
 */
final class ConfigurationStatus
{
    public const READY = 'ready';
    public const DISABLED = 'disabled';
    public const INCOMPLETE = 'incomplete';
    public const ATTENTION = 'attention';

    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const WARN = 'warn';
    public const INFO = 'info';

    /** PayBridge actions this long past due mean background processing is not running. */
    public const STALLED_AFTER_SECONDS = 1800;

    /** @return array{level:string, label:string, checks:list<array{id:string, result:string, label:string, help:string}>} */
    public static function evaluate(Settings $settings): array
    {
        $checks = array();
        $add = static function (string $id, string $result, string $label, string $help = '') use (&$checks): void {
            $checks[] = array('id' => $id, 'result' => $result, 'label' => $label, 'help' => $help);
        };
        $production = $settings->is_production();

        $add('credentials', $settings->has_credentials() ? self::PASS : self::FAIL, __('Plaid Client ID and Secret', 'paybridge-for-plaid'), $settings->has_credentials() ? '' : __('Enter both keys of the selected environment.', 'paybridge-for-plaid'));
        $add('environment', $settings->environment_is_valid() ? self::PASS : self::FAIL, $production ? __('Environment: Production (real money)', 'paybridge-for-plaid') : __('Environment: Sandbox (test mode)', 'paybridge-for-plaid'));
        $https = GatewayAvailability::site_uses_https();
        $add('https', $https ? self::PASS : ($production ? self::FAIL : self::INFO), __('HTTPS', 'paybridge-for-plaid'), $https ? '' : __('Production requires HTTPS.', 'paybridge-for-plaid'));
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $add('currency', Money::SUPPORTED_CURRENCY === $currency ? self::PASS : self::FAIL, __('Store currency USD', 'paybridge-for-plaid'));
        $customization = '' !== $settings->link_customization_name();
        $add(
            'link_customization',
            $customization ? self::PASS : self::FAIL,
            __('Link customization configured', 'paybridge-for-plaid'),
            $customization ? '' : __('Create a Plaid Link customization with Account Select = “Enabled for one account” in the selected environment, then enter its name. Plaid Transfer UI requires it in Sandbox and Production.', 'paybridge-for-plaid')
        );
        $link_error = get_option(PaymentAttemptService::LAST_LINK_ERROR_OPTION, array());
        if (is_array($link_error) && isset($link_error['code'])) {
            $add('link_error', self::FAIL, sprintf(/* translators: %s: Plaid error code */ __('Plaid rejected the last Link session (%s)', 'paybridge-for-plaid'), (string) $link_error['code']), __('Check the Link customization name, that it is published and that its language matches the store language.', 'paybridge-for-plaid'));
        }
        $schema = Installer::schema_is_valid();
        $add('schema', $schema ? self::PASS : self::FAIL, __('Database tables', 'paybridge-for-plaid'), $schema ? '' : __('Deactivate and reactivate the plugin to recreate its tables.', 'paybridge-for-plaid'));
        $rest = function_exists('rest_get_server') && isset(rest_get_server()->get_routes()['/' . RestRoutes::NAMESPACE . '/webhook']);
        $add('rest', $rest ? self::PASS : self::FAIL, __('Webhook REST route', 'paybridge-for-plaid'));

        $connection = get_option(ConnectionTester::LAST_RESULT_OPTION, array());
        if (is_array($connection) && isset($connection['status'])) {
            $connected = 'connected' === $connection['status'];
            $add('connection', $connected ? (array() === ($connection['issues'] ?? array()) ? self::PASS : self::WARN) : self::FAIL, __('Last Plaid connection test', 'paybridge-for-plaid'), ConnectionTester::message($connection));
        } else {
            $add('connection', self::WARN, __('Last Plaid connection test', 'paybridge-for-plaid'), __('Not run yet: use “Test connection”.', 'paybridge-for-plaid'));
        }

        foreach (self::background_checks($settings) as $check) {
            $checks[] = $check;
        }
        $add('enabled', $settings->enabled() ? self::PASS : self::INFO, __('Pay by Bank offered at checkout', 'paybridge-for-plaid'), $settings->enabled() ? '' : __('Disabled: customers cannot choose Pay by Bank. Existing payments are still monitored.', 'paybridge-for-plaid'));

        $results = array_column($checks, 'result', 'id');
        $mandatory_failed = array() !== array_filter(
            array('credentials', 'environment', 'https', 'currency', 'link_customization', 'schema', 'rest'),
            static fn (string $id): bool => self::FAIL === ($results[$id] ?? self::PASS)
        );
        $attention = in_array(self::FAIL, $results, true) || in_array(self::WARN, array_intersect_key($results, array_flip(array('connection', 'scheduler', 'stalled', 'event_sync', 'other_account', 'action_scheduler'))), true);
        if ($mandatory_failed) {
            $level = self::INCOMPLETE;
        } elseif ($attention) {
            $level = self::ATTENTION;
        } elseif (! $settings->enabled()) {
            $level = self::DISABLED;
        } else {
            $level = self::READY;
        }
        return array('level' => $level, 'label' => self::level_label($level), 'checks' => $checks);
    }

    /** @return list<array{id:string, result:string, label:string, help:string}> */
    public static function background_checks(Settings $settings): array
    {
        $checks = array();
        $has_scheduler = function_exists('as_has_scheduled_action') && function_exists('as_get_scheduled_actions');
        $checks[] = array('id' => 'action_scheduler', 'result' => $has_scheduler ? self::PASS : self::FAIL, 'label' => __('Action Scheduler available', 'paybridge-for-plaid'), 'help' => $has_scheduler ? '' : __('PayBridge cannot follow payments without WooCommerce Action Scheduler.', 'paybridge-for-plaid'));
        $active = Scheduler::maintenance_active($settings);
        if ($has_scheduler && $active) {
            $scheduled = as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP);
            $checks[] = array('id' => 'scheduler', 'result' => $scheduled ? self::PASS : self::WARN, 'label' => __('Reconciliation scheduled', 'paybridge-for-plaid'), 'help' => $scheduled ? '' : __('It is scheduled automatically on the next admin or cron request.', 'paybridge-for-plaid'));
            $stalled = self::stalled_actions();
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $checks[] = array(
                'id' => 'stalled',
                'result' => $stalled > 0 ? self::WARN : self::PASS,
                'label' => __('Background jobs running on time', 'paybridge-for-plaid'),
                'help' => $stalled > 0
                    ? ($cron_disabled
                        ? __('PayBridge jobs are overdue and WP-Cron is disabled (DISABLE_WP_CRON). Configure a server cron job that runs wp-cron.php or `wp action-scheduler run` every minute.', 'paybridge-for-plaid')
                        : __('PayBridge jobs are overdue. WP-Cron runs only when the site gets visits; configure a server cron job for reliable payment updates.', 'paybridge-for-plaid'))
                    : ($cron_disabled ? __('WP-Cron is disabled; a server cron job must run the scheduled actions.', 'paybridge-for-plaid') : ''),
            );
        }
        $error = EventSyncService::health($settings->account_scope())['last_error'];
        if (isset($error['at'])) {
            $permanent = 'permanent' === ($error['category'] ?? '');
            $checks[] = array('id' => 'event_sync', 'result' => self::WARN, 'label' => __('Last Plaid event sync failed', 'paybridge-for-plaid'), 'help' => $permanent ? __('Plaid rejected the request: check the credentials with “Test connection”.', 'paybridge-for-plaid') : __('PayBridge retries automatically with backoff.', 'paybridge-for-plaid'));
        }
        if (! $settings->can_reach_plaid() && Installer::schema_is_valid()) {
            $open = ( new PaymentLockStore() )->monitored($settings->environment_name())['count'];
            if ($open > 0) {
                /* translators: %d: number of payments */
                $checks[] = array('id' => 'unmonitored', 'result' => self::FAIL, 'label' => sprintf(__('%d bank payment(s) cannot be monitored', 'paybridge-for-plaid'), $open), 'help' => __('Plaid credentials are missing, so returns and refunds of existing payments are not detected.', 'paybridge-for-plaid'));
            }
        }
        if ($settings->can_reach_plaid() && Installer::schema_is_valid()) {
            $other = ( new PaymentLockStore() )->count_other_account($settings->environment_name(), $settings->account_fingerprint());
            if ($other > 0) {
                /* translators: %d: number of payments */
                $checks[] = array('id' => 'other_account', 'result' => self::WARN, 'label' => sprintf(__('%d payment(s) belong to another Plaid account', 'paybridge-for-plaid'), $other), 'help' => __('They cannot be read with the current Client ID and are not monitored.', 'paybridge-for-plaid'));
            }
        }
        return $checks;
    }

    /** PayBridge actions pending longer than STALLED_AFTER_SECONDS past their scheduled time. */
    public static function stalled_actions(): int
    {
        if (! function_exists('as_get_scheduled_actions') || ! class_exists('ActionScheduler_Store')) {
            return 0;
        }
        $ids = as_get_scheduled_actions(array(
            'group' => Scheduler::GROUP,
            'status' => \ActionScheduler_Store::STATUS_PENDING,
            'date' => gmdate('Y-m-d H:i:s', time() - self::STALLED_AFTER_SECONDS),
            'date_compare' => '<=',
            'per_page' => 50,
        ), 'ids');
        return is_array($ids) ? count($ids) : 0;
    }

    /** Failed PayBridge actions in the last 7 days. */
    public static function failed_actions(): int
    {
        if (! function_exists('as_get_scheduled_actions') || ! class_exists('ActionScheduler_Store')) {
            return 0;
        }
        $ids = as_get_scheduled_actions(array(
            'group' => Scheduler::GROUP,
            'status' => \ActionScheduler_Store::STATUS_FAILED,
            'date' => gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS),
            'date_compare' => '>=',
            'per_page' => 100,
        ), 'ids');
        return is_array($ids) ? count($ids) : 0;
    }

    public static function level_label(string $level): string
    {
        return match ($level) {
            self::READY => __('Ready', 'paybridge-for-plaid'),
            self::DISABLED => __('Disabled', 'paybridge-for-plaid'),
            self::INCOMPLETE => __('Incomplete', 'paybridge-for-plaid'),
            default => __('Attention required', 'paybridge-for-plaid'),
        };
    }

    public static function result_label(string $result): string
    {
        return match ($result) {
            self::PASS => __('PASS', 'paybridge-for-plaid'),
            self::FAIL => __('FAIL', 'paybridge-for-plaid'),
            self::WARN => __('WARN', 'paybridge-for-plaid'),
            default => __('INFO', 'paybridge-for-plaid'),
        };
    }
}
