<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Settings;

use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Plaid\PlaidEnvironment;

/**
 * Protects existing payments from Plaid account and environment changes (ADR-0015).
 *
 * Transfers can only be read by the Plaid client that created them. Switching the Client ID
 * or the environment, or removing the secret, while Production payments or refunds can still
 * change (money in flight, return windows open, refunds unconfirmed) would make ACH returns
 * and refund outcomes undetectable. Such a change is refused: the previous environment,
 * Client ID and secret are kept, every other setting is saved, and the merchant is told why.
 * Rotating the secret of the same Client ID is always allowed. Open Sandbox payments move no
 * real money, so a change is allowed with a warning.
 *
 * The guard runs on the option update itself, so the settings screen, the WooCommerce REST API
 * and WP-CLI are all covered.
 */
final class AccountChangeGuard
{
    public const NOTICE_TRANSIENT = 'bmfp_account_guard_notice_';

    public function __construct(
        private readonly PaymentLockStore $locks = new PaymentLockStore(),
        private readonly RefundStore $refunds = new RefundStore()
    ) {
    }

    public function register(): void
    {
        add_filter('pre_update_option_' . Settings::OPTION, array($this, 'filter'), 10, 2);
        add_action('admin_notices', array($this, 'render_notice'));
    }

    /**
     * @param mixed $new
     * @param mixed $old
     * @return mixed
     */
    public function filter($new, $old)
    {
        if (! is_array($new) || ! is_array($old)) {
            return $new;
        }
        $decision = $this->evaluate(Settings::from_array($old), Settings::from_array($new));
        if ('allowed' === $decision['result']) {
            return $new;
        }
        if ('blocked' === $decision['result']) {
            foreach (array('environment', 'client_id', 'secret') as $key) {
                if (array_key_exists($key, $old)) {
                    $new[$key] = $old[$key];
                } else {
                    unset($new[$key]);
                }
            }
            ( new Logger() )->security('plaid_account_change_blocked', array('environment' => Settings::from_array($old)->environment_name(), 'open_payments' => $decision['payments'], 'open_refunds' => $decision['refunds']));
        }
        $this->remember_notice($decision);
        return $new;
    }

    /**
     * @return array{result:string, payments:int, refunds:int, environment:string} result: allowed | blocked | warning
     */
    public function evaluate(Settings $old, Settings $new): array
    {
        $environment = $old->environment_name();
        $allowed = array('result' => 'allowed', 'payments' => 0, 'refunds' => 0, 'environment' => $environment);
        if ('' === $old->client_id()) {
            return $allowed;
        }
        $switches_account = $old->environment_name() !== $new->environment_name() || $old->account_fingerprint() !== $new->account_fingerprint();
        $loses_access = '' !== $old->secret() && '' === $new->secret();
        if (! $switches_account && ! $loses_access) {
            return $allowed;
        }
        $payments = $this->locks->monitored($environment, $old->account_fingerprint())['count'];
        $refunds = $this->refunds->open_count($environment, $old->account_fingerprint());
        if (0 === $payments + $refunds) {
            return $allowed;
        }
        return array(
            'result' => PlaidEnvironment::PRODUCTION === $environment ? 'blocked' : 'warning',
            'payments' => $payments,
            'refunds' => $refunds,
            'environment' => $environment,
        );
    }

    /** @param array{result:string, payments:int, refunds:int, environment:string} $decision */
    private function remember_notice(array $decision): void
    {
        $message = 'blocked' === $decision['result']
            ? sprintf(
                /* translators: 1: number of open payments, 2: number of open refunds */
                __('Buckmerce kept the previous Plaid environment, Client ID and Secret: %1$d Production payment(s) and %2$d refund(s) are still monitored for ACH returns or refund outcomes, and only the Plaid account that created them can read them. Other settings were saved. You can rotate the secret of the same Client ID at any time; switch accounts after Buckmerce diagnostics show no monitored payments.', 'buckmerce-for-plaid'),
                $decision['payments'],
                $decision['refunds']
            )
            : sprintf(
                /* translators: 1: number of open payments, 2: number of open refunds */
                __('Buckmerce: %1$d open Sandbox payment(s) and %2$d refund(s) of the previous Plaid account/environment are no longer monitored. Sandbox moves no real money.', 'buckmerce-for-plaid'),
                $decision['payments'],
                $decision['refunds']
            );
        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::warning($message);
        }
        // Settings are saved before the admin page renders, so the notice shows on the same screen.
        if (function_exists('get_current_user_id') && get_current_user_id() > 0) {
            set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), array('type' => $decision['result'], 'message' => $message), 10 * MINUTE_IN_SECONDS);
        }
    }

    public function render_notice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }
        $key = self::NOTICE_TRANSIENT . get_current_user_id();
        $notice = get_transient($key);
        if (! is_array($notice) || ! isset($notice['message'], $notice['type'])) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice ' . esc_attr('blocked' === $notice['type'] ? 'notice-error' : 'notice-warning') . '"><p>' . esc_html((string) $notice['message']) . '</p></div>';
    }
}
