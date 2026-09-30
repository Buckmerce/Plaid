<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Admin;

use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Persistence\PaymentEpoch;
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
        $status = ConfigurationStatus::evaluate($settings);
        $problems = array_values(array_filter($status['checks'], static fn (array $check): bool => in_array($check['result'], array(ConfigurationStatus::FAIL, ConfigurationStatus::WARN), true)));
        $details = implode(' ', array_map(static fn (array $check): string => $check['label'] . ('' !== $check['help'] ? ': ' . $check['help'] : '') . '.', $problems));
        if (! $settings->enabled() && null === PaymentEpoch::get($settings->environment_name())) {
            return $this->result('good', __('PayBridge for Plaid is not in use', 'paybridge-for-plaid'), __('The gateway is disabled and no bank payments exist in the configured environment.', 'paybridge-for-plaid'));
        }
        return match ($status['level']) {
            ConfigurationStatus::INCOMPLETE => $this->result('critical', __('PayBridge for Plaid configuration is incomplete', 'paybridge-for-plaid'), $details),
            ConfigurationStatus::ATTENTION => $this->result('recommended', __('PayBridge for Plaid needs attention', 'paybridge-for-plaid'), $details),
            ConfigurationStatus::DISABLED => $this->result('good', __('PayBridge for Plaid is disabled for new payments', 'paybridge-for-plaid'), __('Existing bank payments are still monitored.', 'paybridge-for-plaid')),
            default => $this->result('good', __('PayBridge for Plaid is configured', 'paybridge-for-plaid'), $settings->is_production() ? __('Production is ready to accept bank payments.', 'paybridge-for-plaid') : __('Sandbox is ready for test payments.', 'paybridge-for-plaid')),
        };
    }

    /** @return array<string, mixed> */
    public function test_background(): array
    {
        $settings = Settings::load();
        $checks = ConfigurationStatus::background_checks($settings);
        $failed = array_values(array_filter($checks, static fn (array $check): bool => ConfigurationStatus::FAIL === $check['result']));
        $warned = array_values(array_filter($checks, static fn (array $check): bool => ConfigurationStatus::WARN === $check['result']));
        $describe = static fn (array $list): string => implode(' ', array_map(static fn (array $check): string => $check['label'] . ('' !== $check['help'] ? ': ' . $check['help'] : '') . '.', $list));
        if (array() !== $failed) {
            return $this->result('critical', __('PayBridge cannot follow bank payments in the background', 'paybridge-for-plaid'), $describe($failed));
        }
        if (array() !== $warned) {
            return $this->result('recommended', __('PayBridge background processing needs attention', 'paybridge-for-plaid'), $describe($warned));
        }
        if (! Scheduler::maintenance_active($settings)) {
            return $this->result('good', __('PayBridge background processing is idle', 'paybridge-for-plaid'), __('No bank payments need monitoring.', 'paybridge-for-plaid'));
        }
        return $this->result('good', __('PayBridge background processing is healthy', 'paybridge-for-plaid'), __('Transfer event sync and reconciliation are running.', 'paybridge-for-plaid'));
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
