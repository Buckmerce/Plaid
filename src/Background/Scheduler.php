<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Background;

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Persistence\PaymentLockStore;
use PayBridge\Plaid\Persistence\RefundStore;
use PayBridge\Plaid\Persistence\TransferEventStore;
use PayBridge\Plaid\Settings\AccountScope;
use PayBridge\Plaid\Settings\Settings;

/**
 * Action Scheduler integration. All actions are PayBridge-owned and grouped under
 * paybridge-for-plaid.
 *
 * Accepting new payments and maintaining existing ones are separate (ADR-0014): disabling
 * the gateway only hides Pay by Bank at checkout. The recurring reconciliation keeps running
 * while PayBridge can read Plaid and there is real work for the configured Plaid account:
 * monitored payments, open refunds, unprocessed events or a failed event sync (ADR-0021).
 * When none remains it stops; a verified webhook still triggers event sync at any time.
 */
final class Scheduler
{
    public const GROUP = 'paybridge-for-plaid';
    public const EVENT_SYNC_HOOK = 'paybridge_plaid_transfer_event_sync';
    public const RECONCILE_HOOK = 'paybridge_plaid_reconcile';
    public const RECONCILE_CONTINUE_HOOK = 'paybridge_plaid_reconcile_continue';
    public const RECONCILE_INTERVAL = 15 * MINUTE_IN_SECONDS;

    /** @return list<string> */
    public static function hooks(): array
    {
        return array(self::EVENT_SYNC_HOOK, self::RECONCILE_HOOK, self::RECONCILE_CONTINUE_HOOK);
    }

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

    /**
     * Whether the recurring reconciliation must run: PayBridge can read Plaid, and it either
     * accepts payments or has operational work for the configured account. "A payment once
     * existed" is not work: payments whose return windows closed need nothing more.
     */
    public static function maintenance_active(Settings $settings): bool
    {
        if (! $settings->can_reach_plaid()) {
            return false;
        }
        return $settings->enabled() || self::has_work(self::pending_work($settings->account_scope()));
    }

    /**
     * Operational work that exists independently of the enabled switch. Indexed queries only.
     *
     * @return array{payments:int, refunds:int, events:bool, sync_failures:int}
     */
    public static function pending_work(AccountScope $scope): array
    {
        if (! $scope->is_valid() || ! Installer::schema_is_current()) {
            return array('payments' => 0, 'refunds' => 0, 'events' => false, 'sync_failures' => 0);
        }
        return array(
            'payments' => ( new PaymentLockStore() )->monitored($scope->environment, $scope->account_fp)['count'],
            'refunds' => ( new RefundStore() )->open_count($scope->environment, $scope->account_fp),
            // Includes events deferred for a retry: the next reconciliation claims them when due.
            'events' => ( new TransferEventStore() )->has_backlog($scope),
            // A failed sync may have left events unread at Plaid: keep retrying until one succeeds.
            'sync_failures' => EventSyncService::failures($scope),
        );
    }

    /** @param array{payments:int, refunds:int, events:bool, sync_failures:int} $work */
    public static function has_work(array $work): bool
    {
        return $work['payments'] > 0 || $work['refunds'] > 0 || $work['events'] || $work['sync_failures'] > 0;
    }

    public function run_event_sync(): void
    {
        $settings = Settings::load();
        if (! $settings->can_reach_plaid()) {
            return;
        }
        $sync = $this->container->event_sync();
        $result = $sync->run();
        if ($result['more']) {
            self::enqueue_event_sync(EventSyncService::retry_delay($sync->scope()));
        }
    }

    public function run_reconciliation(): void
    {
        $settings = Settings::load();
        if (! self::maintenance_active($settings)) {
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

    /**
     * Keeps the recurring reconciliation scheduled while maintenance is needed. Runs only in
     * admin, cron and WP-CLI requests so storefront requests pay no scheduling queries.
     */
    public function ensure_recurring(): void
    {
        if (! is_admin() && ! wp_doing_cron() && ! (defined('WP_CLI') && WP_CLI)) {
            return;
        }
        if (! function_exists('as_has_scheduled_action')) {
            return;
        }
        $scheduled = as_has_scheduled_action(self::RECONCILE_HOOK, array(), self::GROUP);
        if (! self::maintenance_active(Settings::load())) {
            if ($scheduled && function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions(self::RECONCILE_HOOK, array(), self::GROUP);
            }
            return;
        }
        if (! $scheduled) {
            as_schedule_recurring_action(time() + self::RECONCILE_INTERVAL, self::RECONCILE_INTERVAL, self::RECONCILE_HOOK, array(), self::GROUP, true);
        }
    }

    /** Deactivation/uninstall: removes only PayBridge-owned actions. */
    public static function unschedule_all(): void
    {
        if (! function_exists('as_unschedule_all_actions')) {
            return;
        }
        foreach (self::hooks() as $hook) {
            as_unschedule_all_actions($hook, array(), self::GROUP);
        }
    }
}
