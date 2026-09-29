<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Plaid\Exception\PlaidException;
use PayBridge\Plaid\Settings\Settings;

/**
 * Read-only Plaid connectivity check (/transfer/configuration/get and
 * /transfer/ledger/get). Stores a non-secret result for the current admin.
 */
final class ConnectionTester
{
    public const ACTION = 'pbfp_test_connection';
    public const TRANSIENT_PREFIX = 'pbfp_connection_test_';
    public const LAST_RESULT_OPTION = 'paybridge_plaid_last_connection_test';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, array($this, 'handle'));
    }

    public function handle(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to run this check.', 'paybridge-for-plaid'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $result = $this->test(Settings::load());
        set_transient(self::TRANSIENT_PREFIX . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        update_option(self::LAST_RESULT_OPTION, $result + array('at' => gmdate('c')), false);
        $referer = wp_get_referer();
        wp_safe_redirect($referer ? $referer : admin_url('admin.php?page=' . DiagnosticsPage::PAGE_SLUG));
        exit;
    }

    /** @return array{status:string, environment:string, ledger?:string, error_code?:string, request_id?:string} */
    public function test(Settings $settings): array
    {
        $environment = $settings->environment_name();
        try {
            $container = new Container($settings);
            $client = $container->client();
            $client->post('/transfer/configuration/get', array());
            $ledger = 'unknown';
            try {
                $client->post('/transfer/ledger/get', array());
                $ledger = 'enabled';
            } catch (PlaidApiException $exception) {
                $ledger = 'not_available';
            }
            return array('status' => 'connected', 'environment' => $environment, 'ledger' => $ledger);
        } catch (ConfigurationException $exception) {
            return array('status' => 'not_configured', 'environment' => $environment);
        } catch (PlaidApiException $exception) {
            return array('status' => 'rejected', 'environment' => $environment, 'error_code' => $exception->error_code, 'request_id' => $exception->request_id());
        } catch (PlaidException $exception) {
            return array('status' => 'unreachable', 'environment' => $environment, 'request_id' => $exception->request_id());
        }
    }

    /** @param array<string, mixed> $result */
    public static function message(array $result): string
    {
        $status = (string) ($result['status'] ?? '');
        $code = preg_replace('/[^A-Z_]/', '', (string) ($result['error_code'] ?? '')) ?? '';
        return match ($status) {
            'connected' => 'enabled' === ($result['ledger'] ?? '')
                ? __('Connected to Plaid Transfer. Plaid Ledger is enabled (leave Funding Account ID empty).', 'paybridge-for-plaid')
                : __('Connected to Plaid Transfer.', 'paybridge-for-plaid'),
            'not_configured' => __('Enter the Client ID and Secret, save, then test again.', 'paybridge-for-plaid'),
            /* translators: %s: Plaid error code such as INVALID_API_KEYS */
            'rejected' => sprintf(__('Plaid rejected the request (%s). Check the credentials, the environment and that Transfer is enabled for your account.', 'paybridge-for-plaid'), '' === $code ? 'UNKNOWN' : $code),
            'unreachable' => __('Plaid could not be reached from this server. Check outbound HTTPS connectivity.', 'paybridge-for-plaid'),
            default => __('No connection test has been run.', 'paybridge-for-plaid'),
        };
    }
}
