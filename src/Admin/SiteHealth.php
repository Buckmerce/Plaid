<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Background\EventSyncService;
use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Gateway\GatewayAvailability;
use PayBridge\Plaid\Persistence\Installer;
use PayBridge\Plaid\Settings\Settings;

/** Site Health tests. Messages never include credential values. */
final class SiteHealth
{
    public function register(): void
    {
        add_filter('site_status_tests', array($this, 'tests'));
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function tests(array $tests): array
    {
        $tests['direct']['paybridge_plaid_configuration'] = array('label' => __('PayBridge for Plaid configuration', 'paybridge-for-plaid'), 'test' => array($this, 'test_configuration'));
        $tests['direct']['paybridge_plaid_background'] = array('label' => __('PayBridge for Plaid background processing', 'paybridge-for-plaid'), 'test' => array($this, 'test_background'));
        return $tests;
    }

    /** @return array<string, mixed> */
    public function test_configuration(): array
    {
        $settings = Settings::load();
        if (! $settings->enabled()) {
            return $this->result('good', __('PayBridge for Plaid is disabled', 'paybridge-for-plaid'), __('The gateway is not enabled, so no checks apply.', 'paybridge-for-plaid'));
        }
        if (! Installer::schema_is_valid()) {
            return $this->result('critical', __('PayBridge database tables are invalid', 'paybridge-for-plaid'), __('Deactivate and reactivate the plugin to recreate its tables.', 'paybridge-for-plaid'));
        }
        $problems = GatewayAvailability::problems($settings, get_woocommerce_currency(), GatewayAvailability::site_uses_https());
        if (in_array(GatewayAvailability::PRODUCTION_REQUIRES_HTTPS, $problems, true)) {
            return $this->result('critical', __('PayBridge Production requires HTTPS', 'paybridge-for-plaid'), __('Pay by Bank is hidden until the site uses HTTPS.', 'paybridge-for-plaid'));
        }
        if (array() !== $problems) {
            return $this->result('recommended', __('PayBridge for Plaid configuration is incomplete', 'paybridge-for-plaid'), implode(' ', array_map(array(GatewayAvailability::class, 'label'), $problems)));
        }
        $connection = get_option(ConnectionTester::LAST_RESULT_OPTION, array());
        if (is_array($connection) && isset($connection['status']) && 'connected' !== $connection['status']) {
            return $this->result('recommended', __('The last Plaid connection test failed', 'paybridge-for-plaid'), ConnectionTester::message($connection));
        }
        return $this->result('good', __('PayBridge for Plaid is configured', 'paybridge-for-plaid'), __('Credentials are present and the configuration allows Pay by Bank.', 'paybridge-for-plaid'));
    }

    /** @return array<string, mixed> */
    public function test_background(): array
    {
        $settings = Settings::load();
        if (! $settings->enabled()) {
            return $this->result('good', __('PayBridge background processing is idle', 'paybridge-for-plaid'), __('The gateway is not enabled.', 'paybridge-for-plaid'));
        }
        if (! function_exists('as_has_scheduled_action')) {
            return $this->result('critical', __('Action Scheduler is unavailable', 'paybridge-for-plaid'), __('PayBridge cannot process Plaid transfer events without Action Scheduler.', 'paybridge-for-plaid'));
        }
        if ($settings->reconciliation_enabled() && ! as_has_scheduled_action(Scheduler::RECONCILE_HOOK, array(), Scheduler::GROUP)) {
            return $this->result('recommended', __('PayBridge reconciliation is not scheduled yet', 'paybridge-for-plaid'), __('It is scheduled automatically on the next admin or cron request.', 'paybridge-for-plaid'));
        }
        $error = get_option(EventSyncService::LAST_ERROR_OPTION, array());
        if (is_array($error) && isset($error['at'])) {
            return $this->result('recommended', __('The last Plaid event sync failed', 'paybridge-for-plaid'), __('PayBridge will retry automatically. See WooCommerce → Status → Logs (paybridge-for-plaid).', 'paybridge-for-plaid'));
        }
        return $this->result('good', __('PayBridge background processing is healthy', 'paybridge-for-plaid'), __('Transfer event sync and reconciliation are available.', 'paybridge-for-plaid'));
    }

    /** @return array<string, mixed> */
    private function result(string $status, string $label, string $description): array
    {
        return array(
            'label' => $label,
            'status' => $status,
            'badge' => array('label' => __('Payments', 'paybridge-for-plaid'), 'color' => 'critical' === $status ? 'red' : 'blue'),
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => '<p><a href="' . esc_url(DiagnosticsPage::url()) . '">' . esc_html__('Open PayBridge diagnostics', 'paybridge-for-plaid') . '</a></p>',
            'test' => 'paybridge_plaid',
        );
    }
}
