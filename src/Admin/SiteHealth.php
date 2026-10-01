<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Admin;

use Buckmerce\Plaid\Background\Scheduler;
use Buckmerce\Plaid\Persistence\Installer;
use Buckmerce\Plaid\Persistence\PaymentLockStore;
use Buckmerce\Plaid\Persistence\RefundStore;
use Buckmerce\Plaid\Settings\Settings;

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
        $tests['direct']['buckmerce_plaid_configuration'] = array('label' => __('Buckmerce for Plaid configuration', 'buckmerce-for-plaid'), 'test' => array($this, 'test_configuration'));
        $tests['direct']['buckmerce_plaid_background'] = array('label' => __('Buckmerce for Plaid background processing', 'buckmerce-for-plaid'), 'test' => array($this, 'test_background'));
        return $tests;
    }

    /** @return array<string, mixed> */
    public function test_configuration(): array
    {
        $settings = Settings::load();
        $status = ConfigurationStatus::evaluate($settings);
        $problems = array_values(array_filter($status['checks'], static fn (array $check): bool => in_array($check['result'], array(ConfigurationStatus::FAIL, ConfigurationStatus::WARN), true)));
        $details = implode(' ', array_map(static fn (array $check): string => $check['label'] . ('' !== $check['help'] ? ': ' . $check['help'] : '') . '.', $problems));
        if (! $settings->enabled() && ! self::has_open_payments($settings) && ! Scheduler::has_work(Scheduler::pending_work($settings->account_scope()))) {
            return $this->result('good', __('Buckmerce for Plaid is not in use', 'buckmerce-for-plaid'), __('The gateway is disabled and no bank payment or refund of the configured Plaid account needs monitoring.', 'buckmerce-for-plaid'));
        }
        return match ($status['level']) {
            ConfigurationStatus::INCOMPLETE => $this->result('critical', __('Buckmerce for Plaid configuration is incomplete', 'buckmerce-for-plaid'), $details),
            ConfigurationStatus::ATTENTION => $this->result('recommended', __('Buckmerce for Plaid needs attention', 'buckmerce-for-plaid'), $details),
            ConfigurationStatus::DISABLED => $this->result('good', __('Buckmerce for Plaid is disabled for new payments', 'buckmerce-for-plaid'), __('Existing bank payments are still monitored.', 'buckmerce-for-plaid')),
            default => $this->result('good', __('Buckmerce for Plaid is configured', 'buckmerce-for-plaid'), $settings->is_production() ? __('Production is ready to accept bank payments.', 'buckmerce-for-plaid') : __('Sandbox is ready for test payments.', 'buckmerce-for-plaid')),
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
            return $this->result('critical', __('Buckmerce cannot follow bank payments in the background', 'buckmerce-for-plaid'), $describe($failed));
        }
        if (array() !== $warned) {
            return $this->result('recommended', __('Buckmerce background processing needs attention', 'buckmerce-for-plaid'), $describe($warned));
        }
        if (! Scheduler::maintenance_active($settings)) {
            return $this->result('good', __('Buckmerce background processing is idle', 'buckmerce-for-plaid'), __('No bank payments need monitoring.', 'buckmerce-for-plaid'));
        }
        return $this->result('good', __('Buckmerce background processing is healthy', 'buckmerce-for-plaid'), __('Transfer event sync and reconciliation are running.', 'buckmerce-for-plaid'));
    }

    /** Payments or refunds of ANY Plaid account in the environment that can still change (credentials or not). */
    private static function has_open_payments(Settings $settings): bool
    {
        if (! Installer::schema_is_current()) {
            return false;
        }
        return ( new PaymentLockStore() )->monitored($settings->environment_name())['count'] > 0
            || ( new RefundStore() )->open_count($settings->environment_name()) > 0;
    }

    /** @return array<string, mixed> */
    private function result(string $status, string $label, string $description): array
    {
        return array(
            'label' => $label,
            'status' => $status,
            'badge' => array('label' => __('Payments', 'buckmerce-for-plaid'), 'color' => 'critical' === $status ? 'red' : 'blue'),
            'description' => '<p>' . esc_html($description) . '</p>',
            'actions' => '<p><a href="' . esc_url(DiagnosticsPage::url()) . '">' . esc_html__('Open Buckmerce diagnostics', 'buckmerce-for-plaid') . '</a></p>',
            'test' => 'buckmerce_plaid',
        );
    }
}
