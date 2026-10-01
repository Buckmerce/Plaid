<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Admin;

use Buckmerce\Plaid\Background\EventSyncService;
use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Gateway\GatewayAvailability;
use Buckmerce\Plaid\Payment\PaymentAttemptService;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\REST\RestRoutes;
use Buckmerce\Plaid\Settings\Settings;
use Buckmerce\Plaid\Support\Money;

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

    /** Buckmerce actions this long past due mean background processing is not running. */
    public const STALLED_AFTER_SECONDS = 1800;

    /** @return array{level:string, label:string, checks:list<array{id:string, result:string, label:string, help:string}>} */
    public static function evaluate(Settings $settings): array
    {
        $checks = array();
        $add = static function (string $id, string $result, string $label, string $help = '') use (&$checks): void {
            $checks[] = array('id' => $id, 'result' => $result, 'label' => $label, 'help' => $help);
        };
        $production = $settings->is_production();

        $add('credentials', $settings->has_credentials() ? self::PASS : self::FAIL, __('Plaid Client ID and Secret', 'buckmerce-for-plaid'), $settings->has_credentials() ? '' : __('Enter both keys of the selected environment.', 'buckmerce-for-plaid'));
        $add('environment', $settings->environment_is_valid() ? self::PASS : self::FAIL, $production ? __('Environment: Production (real money)', 'buckmerce-for-plaid') : __('Environment: Sandbox (test mode)', 'buckmerce-for-plaid'));
        $https = GatewayAvailability::site_uses_https();
        $add('https', $https ? self::PASS : ($production ? self::FAIL : self::INFO), __('HTTPS', 'buckmerce-for-plaid'), $https ? '' : __('Production requires HTTPS.', 'buckmerce-for-plaid'));
        $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
        $add('currency', Money::SUPPORTED_CURRENCY === $currency ? self::PASS : self::FAIL, __('Store currency USD', 'buckmerce-for-plaid'));
        $customization = '' !== $settings->link_customization_name();
        $add(
            'link_customization',
            $customization ? self::PASS : self::FAIL,
            __('Link customization configured', 'buckmerce-for-plaid'),
            $customization ? '' : __('Create a Plaid Link customization with Account Select = “Enabled for one account” in the selected environment, then enter its name. Plaid Transfer UI requires it in Sandbox and Production.', 'buckmerce-for-plaid')
        );
        $link_error = get_option(PaymentAttemptService::LAST_LINK_ERROR_OPTION, array());
        if (is_array($link_error) && isset($link_error['code'])) {
            $add('link_error', self::FAIL, sprintf(/* translators: %s: Plaid error code */ __('Plaid rejected the last Link session (%s)', 'buckmerce-for-plaid'), (string) $link_error['code']), __('Check the Link customization name, that it is published and that its language matches the store language.', 'buckmerce-for-plaid'));
        }
        $schema = Installer::schema_is_valid();
        $add('schema', $schema ? self::PASS : self::FAIL, __('Database tables', 'buckmerce-for-plaid'), $schema ? '' : __('Deactivate and reactivate the plugin to recreate its tables.', 'buckmerce-for-plaid'));
        $rest = function_exists('rest_get_server') && isset(rest_get_server()->get_routes()['/' . RestRoutes::NAMESPACE . '/webhook']);
        $add('rest', $rest ? self::PASS : self::FAIL, __('Webhook REST route', 'buckmerce-for-plaid'));

        $connection = get_option(ConnectionTester::LAST_RESULT_OPTION, array());
        if (is_array($connection) && isset($connection['status'])) {
            $connected = 'connected' === $connection['status'];
            $add('connection', $connected ? (array() === ($connection['issues'] ?? array()) ? self::PASS : self::WARN) : self::FAIL, __('Last Plaid connection test', 'buckmerce-for-plaid'), ConnectionTester::message($connection));
        } else {
            $add('connection', self::WARN, __('Last Plaid connection test', 'buckmerce-for-plaid'), __('Not run yet: use “Test connection”.', 'buckmerce-for-plaid'));
        }

        foreach (self::background_checks($settings) as $check) {
            $checks[] = $check;
        }
        $add('enabled', $settings->enabled() ? self::PASS : self::INFO, __('Pay by Bank offered at checkout', 'buckmerce-for-plaid'), $settings->enabled() ? '' : __('Disabled: customers cannot choose Pay by Bank. Existing payments are still monitored.', 'buckmerce-for-plaid'));

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
        $checks[] = array('id' => 'action_scheduler', 'result' => $has_scheduler ? self::PASS : self::FAIL, 'label' => __('Action Scheduler available', 'buckmerce-for-plaid'), 'help' => $has_scheduler ? '' : __('Buckmerce cannot follow payments without WooCommerce Action Scheduler.', 'buckmerce-for-plaid'));
        $active = Scheduler::maintenance_active($settings);
        if ($has_scheduler && $active) {
            $scheduled = as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP);
            $checks[] = array('id' => 'scheduler', 'result' => $scheduled ? self::PASS : self::WARN, 'label' => __('Reconciliation scheduled', 'buckmerce-for-plaid'), 'help' => $scheduled ? '' : __('It is scheduled automatically on the next admin or cron request.', 'buckmerce-for-plaid'));
            $stalled = self::stalled_actions();
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $checks[] = array(
                'id' => 'stalled',
                'result' => $stalled > 0 ? self::WARN : self::PASS,
                'label' => __('Background jobs running on time', 'buckmerce-for-plaid'),
                'help' => $stalled > 0
                    ? ($cron_disabled
                        ? __('Buckmerce jobs are overdue and WP-Cron is disabled (DISABLE_WP_CRON). Configure a server cron job that runs wp-cron.php or `wp action-scheduler run` every minute.', 'buckmerce-for-plaid')
                        : __('Buckmerce jobs are overdue. WP-Cron runs only when the site gets visits; configure a server cron job for reliable payment updates.', 'buckmerce-for-plaid'))
                    : ($cron_disabled ? __('WP-Cron is disabled; a server cron job must run the scheduled actions.', 'buckmerce-for-plaid') : ''),
            );
        }
        $error = EventSyncService::health($settings->account_scope())['last_error'];
        if (isset($error['at'])) {
            $permanent = 'permanent' === ($error['category'] ?? '');
            $checks[] = array('id' => 'event_sync', 'result' => self::WARN, 'label' => __('Last Plaid event sync failed', 'buckmerce-for-plaid'), 'help' => $permanent ? __('Plaid rejected the request: check the credentials with “Test connection”.', 'buckmerce-for-plaid') : __('Buckmerce retries automatically with backoff.', 'buckmerce-for-plaid'));
        }
        if (! $settings->can_reach_plaid() && Installer::schema_is_valid()) {
            $open = ( new PaymentLockStore() )->monitored($settings->environment_name())['count'];
            if ($open > 0) {
                /* translators: %d: number of payments */
                $checks[] = array('id' => 'unmonitored', 'result' => self::FAIL, 'label' => sprintf(__('%d bank payment(s) cannot be monitored', 'buckmerce-for-plaid'), $open), 'help' => __('Plaid credentials are missing, so returns and refunds of existing payments are not detected.', 'buckmerce-for-plaid'));
            }
        }
        if ($settings->can_reach_plaid() && Installer::schema_is_valid()) {
            $other = ( new PaymentLockStore() )->count_other_account($settings->environment_name(), $settings->account_fingerprint());
            if ($other > 0) {
                /* translators: %d: number of payments */
                $checks[] = array('id' => 'other_account', 'result' => self::WARN, 'label' => sprintf(__('%d payment(s) belong to another Plaid account', 'buckmerce-for-plaid'), $other), 'help' => __('They cannot be read with the current Client ID and are not monitored.', 'buckmerce-for-plaid'));
            }
        }
        return $checks;
    }

    /** Buckmerce actions pending longer than STALLED_AFTER_SECONDS past their scheduled time. */
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

    /** Failed Buckmerce actions in the last 7 days. */
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
            self::READY => __('Ready', 'buckmerce-for-plaid'),
            self::DISABLED => __('Disabled', 'buckmerce-for-plaid'),
            self::INCOMPLETE => __('Incomplete', 'buckmerce-for-plaid'),
            default => __('Attention required', 'buckmerce-for-plaid'),
        };
    }

    public static function result_label(string $result): string
    {
        return match ($result) {
            self::PASS => __('PASS', 'buckmerce-for-plaid'),
            self::FAIL => __('FAIL', 'buckmerce-for-plaid'),
            self::WARN => __('WARN', 'buckmerce-for-plaid'),
            default => __('INFO', 'buckmerce-for-plaid'),
        };
    }
}
