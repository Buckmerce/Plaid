<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Admin;

use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Exception\ConfigurationException;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Exception\PlaidException;
use Buckmerce\Plaid\Plaid\Exception\PlaidNetworkException;
use Buckmerce\Plaid\Settings\Settings;

/**
 * Read-only Plaid readiness check (/transfer/configuration/get and /transfer/ledger/get).
 * Classifies failures so the merchant knows what to fix, and stores a non-secret result.
 */
final class ConnectionTester
{
    public const ACTION = 'bmfp_test_connection';
    public const TRANSIENT_PREFIX = 'bmfp_connection_test_';
    public const LAST_RESULT_OPTION = 'buckmerce_plaid_last_connection_test';

    public const CONNECTED = 'connected';
    public const NOT_CONFIGURED = 'not_configured';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const PRODUCT_NOT_ENABLED = 'product_not_enabled';
    public const PERMISSION_DENIED = 'permission_denied';
    public const RATE_LIMITED = 'rate_limited';
    public const PLAID_UNAVAILABLE = 'plaid_unavailable';
    public const NETWORK_ERROR = 'network_error';
    public const REJECTED = 'rejected';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, array($this, 'handle'));
    }

    public function handle(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You are not allowed to run this check.', 'buckmerce-plaid'), '', array('response' => 403));
        }
        check_admin_referer(self::ACTION);
        $result = $this->test(Settings::load());
        set_transient(self::TRANSIENT_PREFIX . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS);
        update_option(self::LAST_RESULT_OPTION, $result + array('at' => gmdate('c')), false);
        $referer = wp_get_referer();
        wp_safe_redirect($referer ? $referer : admin_url('admin.php?page=' . DiagnosticsPage::PAGE_SLUG));
        exit;
    }

    /** @return array{status:string, environment:string, ledger?:string, error_code?:string, request_id?:string, issues?:list<string>} */
    public function test(Settings $settings): array
    {
        $environment = $settings->environment_name();
        try {
            $client = ( new Container($settings) )->client();
            $client->post('/transfer/configuration/get', array());
            $ledger = 'unknown';
            try {
                $client->post('/transfer/ledger/get', array());
                $ledger = 'enabled';
            } catch (PlaidApiException $exception) {
                $ledger = $exception->is_transient() ? 'unknown' : 'not_available';
            } catch (PlaidException $exception) {
                $ledger = 'unknown';
            }
            $issues = array();
            if ($settings->link_customization_required() && '' === $settings->link_customization_name()) {
                $issues[] = 'missing_link_customization';
            }
            if ('enabled' === $ledger && '' !== $settings->funding_account_id()) {
                $issues[] = 'funding_account_conflict';
            }
            if ('not_available' === $ledger && '' === $settings->funding_account_id()) {
                $issues[] = 'funding_account_required';
            }
            return array('status' => self::CONNECTED, 'environment' => $environment, 'ledger' => $ledger, 'issues' => $issues);
        } catch (ConfigurationException $exception) {
            return array('status' => self::NOT_CONFIGURED, 'environment' => $environment);
        } catch (PlaidApiException $exception) {
            return array('status' => self::classify($exception), 'environment' => $environment, 'error_code' => $exception->error_code, 'request_id' => $exception->request_id());
        } catch (PlaidNetworkException $exception) {
            return array('status' => self::NETWORK_ERROR, 'environment' => $environment);
        } catch (PlaidException $exception) {
            return array('status' => self::PLAID_UNAVAILABLE, 'environment' => $environment, 'request_id' => $exception->request_id());
        }
    }

    public static function classify(PlaidApiException $exception): string
    {
        $code = strtoupper($exception->error_code);
        return match (true) {
            in_array($code, array('INVALID_API_KEYS', 'UNAUTHORIZED_ENVIRONMENT', 'INVALID_CLIENT_ID', 'INVALID_SECRET'), true) => self::INVALID_CREDENTIALS,
            in_array($code, array('INVALID_PRODUCT', 'PRODUCT_NOT_ENABLED', 'PRODUCTS_NOT_SUPPORTED', 'SANDBOX_PRODUCT_NOT_ENABLED'), true) => self::PRODUCT_NOT_ENABLED,
            in_array($code, array('UNAUTHORIZED_ACCESS', 'UNAUTHORIZED_ROUTE_ACCESS', 'ADDITIONAL_CONSENT_REQUIRED'), true) => self::PERMISSION_DENIED,
            429 === $exception->http_status || 'RATE_LIMIT_EXCEEDED' === strtoupper($exception->error_type) => self::RATE_LIMITED,
            $exception->is_ambiguous() || 'PLANNED_MAINTENANCE' === $code => self::PLAID_UNAVAILABLE,
            default => self::REJECTED,
        };
    }

    /** @param array<string, mixed> $result */
    public static function message(array $result): string
    {
        $status = (string) ($result['status'] ?? '');
        $code = preg_replace('/[^A-Z_]/', '', strtoupper((string) ($result['error_code'] ?? ''))) ?? '';
        $code = '' === $code ? 'UNKNOWN' : $code;
        $message = match ($status) {
            self::CONNECTED => 'enabled' === ($result['ledger'] ?? '')
                ? __('Connected to Plaid Transfer. Plaid Ledger is enabled (leave Funding Account ID empty).', 'buckmerce-plaid')
                : __('Connected to Plaid Transfer.', 'buckmerce-plaid'),
            self::NOT_CONFIGURED => __('Configuration incomplete: enter the Client ID and Secret, save, then test again.', 'buckmerce-plaid'),
            /* translators: %s: Plaid error code such as INVALID_API_KEYS */
            self::INVALID_CREDENTIALS => sprintf(__('Invalid Plaid credentials (%s): check the Client ID and that the Secret belongs to the selected environment.', 'buckmerce-plaid'), $code),
            /* translators: %s: Plaid error code */
            self::PRODUCT_NOT_ENABLED => sprintf(__('Plaid Transfer is not enabled for this Plaid account/environment (%s). Request Transfer access in the Plaid Dashboard.', 'buckmerce-plaid'), $code),
            /* translators: %s: Plaid error code */
            self::PERMISSION_DENIED => sprintf(__('Plaid denied access to Transfer (%s). Your team may not be approved for this environment yet.', 'buckmerce-plaid'), $code),
            self::RATE_LIMITED => __('Plaid rate-limited the check. Wait a minute and test again.', 'buckmerce-plaid'),
            self::PLAID_UNAVAILABLE => __('Plaid is temporarily unavailable or returned an unexpected response. Try again later; see status.plaid.com.', 'buckmerce-plaid'),
            self::NETWORK_ERROR => __('Plaid could not be reached from this server (network error). Check outbound HTTPS connectivity and firewall rules.', 'buckmerce-plaid'),
            /* translators: %s: Plaid error code */
            self::REJECTED => sprintf(__('Plaid rejected the request (%s). Check the credentials, the environment and that Transfer is enabled for your account.', 'buckmerce-plaid'), $code),
            default => __('No connection test has been run.', 'buckmerce-plaid'),
        };
        $issues = is_array($result['issues'] ?? null) ? $result['issues'] : array();
        foreach ($issues as $issue) {
            $message .= ' ' . match ((string) $issue) {
                'missing_link_customization' => __('Link customization missing: Plaid Transfer UI requires one with Account Select “Enabled for one account” in this environment.', 'buckmerce-plaid'),
                'funding_account_conflict' => __('Plaid Ledger is enabled, so remove the Funding Account ID (Plaid rejects it).', 'buckmerce-plaid'),
                'funding_account_required' => __('No Plaid Ledger was found: enter your Funding Account ID from the Plaid Dashboard.', 'buckmerce-plaid'),
                default => '',
            };
        }
        return trim($message);
    }
}
