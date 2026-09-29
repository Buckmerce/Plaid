<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Background;

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Settings\Settings;

/** Action Scheduler integration. All actions are PayBridge-owned and grouped under paybridge-for-plaid. */
final class Scheduler
{
    public const GROUP = 'paybridge-for-plaid';
    public const EVENT_SYNC_HOOK = 'paybridge_plaid_transfer_event_sync';
    public const RECONCILE_HOOK = 'paybridge_plaid_reconcile';
    public const RECONCILE_CONTINUE_HOOK = 'paybridge_plaid_reconcile_continue';
    public const RECONCILE_INTERVAL = 15 * MINUTE_IN_SECONDS;

    public function __construct(private readonly Container $container)
    {
    }

    public function register(): void
    {
        add_action(self::EVENT_SYNC_HOOK, array($this, 'run_event_sync'));
        add_action(self::RECONCILE_HOOK, array($this, 'run_reconciliation'));
        add_action(self::RECONCILE_CONTINUE_HOOK, array($this, 'run_reconciliation'));
        add_action('action_scheduler_init', array($this, 'ensure_recurring'));
    }

    public function run_event_sync(): void
    {
        $settings = Settings::load();
        if (! $settings->has_credentials()) {
            return;
        }
        $result = $this->container->event_sync()->run();
        if ($result['more']) {
            self::enqueue_event_sync(MINUTE_IN_SECONDS);
        }
    }

    public function run_reconciliation(): void
    {
        $settings = Settings::load();
        if (! $settings->has_credentials() || ! $settings->reconciliation_enabled()) {
            return;
        }
        $result = $this->container->reconciliation()->run();
        if ($result['more'] && function_exists('as_has_scheduled_action') && ! as_has_scheduled_action(self::RECONCILE_CONTINUE_HOOK, array(), self::GROUP)) {
            as_schedule_single_action(time() + MINUTE_IN_SECONDS, self::RECONCILE_CONTINUE_HOOK, array(), self::GROUP, true);
        }
    }

    /** Called for every verified TRANSFER_EVENTS_UPDATE webhook. Unique: duplicate webhooks coalesce. */
    public static function enqueue_event_sync(int $delay = 0): bool
    {
        if (! function_exists('as_schedule_single_action')) {
            return false;
        }
        if (as_has_scheduled_action(self::EVENT_SYNC_HOOK, array(), self::GROUP)) {
            return true;
        }
        return 0 !== as_schedule_single_action(time() + $delay, self::EVENT_SYNC_HOOK, array(), self::GROUP, true);
    }

    public function ensure_recurring(): void
    {
        $settings = Settings::load();
        if (! $settings->reconciliation_enabled() || ! $settings->enabled() || ! $settings->has_credentials()) {
            if (function_exists('as_unschedule_all_actions') && function_exists('as_has_scheduled_action') && as_has_scheduled_action(self::RECONCILE_HOOK, array(), self::GROUP)) {
                as_unschedule_all_actions(self::RECONCILE_HOOK, array(), self::GROUP);
            }
            return;
        }
        if (function_exists('as_has_scheduled_action') && ! as_has_scheduled_action(self::RECONCILE_HOOK, array(), self::GROUP)) {
            as_schedule_recurring_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, array(), self::GROUP, true);
        }
    }

    /** Deactivation/uninstall: removes only PayBridge-owned actions. */
    public static function unschedule_all(): void
    {
        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }
        foreach (array(self::EVENT_SYNC_HOOK, self::RECONCILE_HOOK, self::RECONCILE_CONTINUE_HOOK) as $hook) {
            as_unschedule_all_actions($hook, array(), self::GROUP);
        }
    }
}
