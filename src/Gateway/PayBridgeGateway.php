<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Gateway;

use PayBridge\Plaid\Admin\ConnectionTester;
use PayBridge\Plaid\Checkout\PaymentAccess;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Exception\PaymentAttemptBusyException;
use PayBridge\Plaid\Exception\PayBridgeException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\Settings\Settings;

/**
 * WooCommerce adapter: settings, availability and process_payment()
 * coordination. It contains no Plaid wire-format code.
 */
final class PayBridgeGateway extends \WC_Payment_Gateway
{
    private const RESET_SECRET_VALUE = 'pbfp_reset_secret';

    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = __('PayBridge for Plaid', 'paybridge-for-plaid');
        $this->method_description = __('Pay by Bank through Plaid Transfer. Customers authorize a one-time ACH debit in Plaid Transfer UI; orders are updated from verified Plaid transfer events.', 'paybridge-for-plaid');
        $this->has_fields = false;
        $this->supports = array('products');
        $this->icon = PAYBRIDGE_PLAID_URL . 'assets/images/paybridge-mark.svg';
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title', __('Pay by Bank', 'paybridge-for-plaid'));
        $this->description = $this->get_option('description', __('Securely pay directly from your bank account.', 'paybridge-for-plaid'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
    }

    public function init_form_fields(): void
    {
        $this->form_fields = array(
            'enabled' => array('title' => __('Enable/Disable', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Enable PayBridge', 'paybridge-for-plaid'), 'default' => 'no'),
            'title' => array('title' => __('Title', 'paybridge-for-plaid'), 'type' => 'text', 'default' => __('Pay by Bank', 'paybridge-for-plaid'), 'desc_tip' => true, 'description' => __('Payment method name shown at checkout.', 'paybridge-for-plaid')),
            'description' => array('title' => __('Description', 'paybridge-for-plaid'), 'type' => 'textarea', 'default' => __('Securely pay directly from your bank account.', 'paybridge-for-plaid')),
            'plaid' => array('title' => __('Plaid account', 'paybridge-for-plaid'), 'type' => 'title', 'description' => __('API keys are in the Plaid Dashboard under Developers → Keys. Plaid Transfer must be enabled for your account.', 'paybridge-for-plaid')),
            'environment' => array('title' => __('Environment', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'sandbox', 'options' => array('sandbox' => __('Sandbox', 'paybridge-for-plaid'), 'production' => __('Production', 'paybridge-for-plaid')), 'description' => __('Production moves real money and requires HTTPS.', 'paybridge-for-plaid')),
            'client_id' => array('title' => __('Client ID', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'custom_attributes' => array('autocomplete' => 'off', 'spellcheck' => 'false')),
            'secret' => array('title' => __('Secret', 'paybridge-for-plaid'), 'type' => 'pbfp_secret', 'default' => '', 'description' => __('Use the secret of the selected environment. Leave blank when saving to keep the stored secret.', 'paybridge-for-plaid')),
            'funding_account_id' => array('title' => __('Funding Account ID (optional)', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'description' => __('Leave empty when your Plaid Transfer account uses Plaid Ledger (the default). Only accounts without a Ledger configure a funding account ID here.', 'paybridge-for-plaid')),
            'link_customization_name' => array('title' => __('Link customization name (optional)', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'description' => __('Name of a Plaid Link customization with Account Select set to “Enabled for one account”, as recommended for Transfer UI.', 'paybridge-for-plaid')),
            'payments' => array('title' => __('Payments', 'paybridge-for-plaid'), 'type' => 'title'),
            'network' => array('title' => __('Payment network', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'same-day-ach', 'options' => array('same-day-ach' => __('Same Day ACH', 'paybridge-for-plaid'), 'ach' => __('Standard ACH', 'paybridge-for-plaid'))),
            'ach_class' => array('title' => __('ACH class', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'web', 'options' => array('web' => __('WEB — consumer authorized online (recommended)', 'paybridge-for-plaid'), 'ppd' => __('PPD — prearranged consumer payment', 'paybridge-for-plaid'), 'ccd' => __('CCD — corporate account', 'paybridge-for-plaid'), 'tel' => __('TEL — telephone authorization', 'paybridge-for-plaid'))),
            'confirmation_state' => array('title' => __('Mark order paid when', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'funds_available', 'options' => array('funds_available' => __('Funds are available (recommended)', 'paybridge-for-plaid'), 'settled' => __('Transfer is settled', 'paybridge-for-plaid')), 'description' => __('Until then orders stay On hold. ACH debits can still be returned later; returns are always recorded and flagged.', 'paybridge-for-plaid')),
            'advanced' => array('title' => __('Operations', 'paybridge-for-plaid'), 'type' => 'title'),
            'reconciliation_enabled' => array('title' => __('Reconciliation', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Re-check open payments with Plaid in the background', 'paybridge-for-plaid'), 'default' => 'yes', 'description' => __('Recovers from missed webhooks. Strongly recommended.', 'paybridge-for-plaid')),
            'debug' => array('title' => __('Debug logging', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Write redacted debug logs (WooCommerce → Status → Logs)', 'paybridge-for-plaid'), 'default' => 'no'),
            'delete_data_on_uninstall' => array('title' => __('Uninstall cleanup', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Delete PayBridge settings, event history and tables when the plugin is deleted', 'paybridge-for-plaid'), 'default' => 'no', 'description' => __('Order payment records stay on the orders for auditing.', 'paybridge-for-plaid')),
        );
    }

    public static function settings_url(): string
    {
        return add_query_arg(array('page' => 'wc-settings', 'tab' => 'checkout', 'section' => Settings::GATEWAY_ID), admin_url('admin.php'));
    }

    public static function enqueue_admin_assets(string $hook_suffix): void
    {
        if ('woocommerce_page_wc-settings' !== $hook_suffix) {
            return;
        }
        $section = isset($_GET['section']) && is_string($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen detection.
        if (Settings::GATEWAY_ID !== $section) {
            return;
        }
        wp_enqueue_style('paybridge-plaid-admin', PAYBRIDGE_PLAID_URL . 'assets/admin-settings.css', array(), PAYBRIDGE_PLAID_VERSION);
        wp_enqueue_script('paybridge-plaid-admin', PAYBRIDGE_PLAID_URL . 'assets/build/admin-settings.js', array(), PAYBRIDGE_PLAID_VERSION, true);
        wp_add_inline_script('paybridge-plaid-admin', 'window.paybridgePlaidAdmin = ' . wp_json_encode(array('copied' => __('Webhook URL copied.', 'paybridge-for-plaid'), 'copyFailed' => __('Select the URL and copy it manually.', 'paybridge-for-plaid'))) . ';', 'before');
    }

    /** Blank secret keeps the stored value; the explicit reset button clears it. */
    public function process_admin_options(): bool
    {
        $stored = get_option($this->get_option_key(), array());
        $field = $this->get_field_key('secret');
        $reset = isset($_POST['save']) && is_string($_POST['save']) && self::RESET_SECRET_VALUE === wp_unslash($_POST['save']); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce before calling this method.
        if ($reset) {
            $_POST[$field] = '';
        } elseif (isset($_POST[$field]) && '' === trim((string) wp_unslash($_POST[$field])) && is_array($stored) && is_string($stored['secret'] ?? null)) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $_POST[$field] = $stored['secret'];
        }
        $client_field = $this->get_field_key('client_id');
        if (isset($_POST[$client_field]) && is_string($_POST[$client_field])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $_POST[$client_field] = preg_replace('/[^A-Za-z0-9]/', '', wp_unslash($_POST[$client_field])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }
        foreach (array('funding_account_id', 'link_customization_name') as $key) {
            $key_field = $this->get_field_key($key);
            if (isset($_POST[$key_field]) && is_string($_POST[$key_field])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
                $_POST[$key_field] = substr(preg_replace('/[^A-Za-z0-9 _\-]/', '', wp_unslash($_POST[$key_field])) ?? '', 0, 100); // phpcs:ignore WordPress.Security.NonceVerification.Missing
            }
        }
        $saved = parent::process_admin_options();
        delete_transient(ConnectionTester::TRANSIENT_PREFIX . get_current_user_id());
        return $saved;
    }

    /**
     * Never renders the stored secret; shows only whether one is configured.
     *
     * @param array<string, mixed> $data
     */
    public function generate_pbfp_secret_html(string $key, array $data): string
    {
        $field_key = $this->get_field_key($key);
        $configured = '' !== (string) $this->get_option($key, '');
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc"><label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html((string) ($data['title'] ?? '')); ?></label></th>
            <td class="forminp">
                <input type="password" autocomplete="new-password" class="input-text regular-input" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="" placeholder="<?php echo esc_attr($configured ? '••••••••••••' : ''); ?>" />
                <p class="description"><?php echo esc_html((string) ($data['description'] ?? '')); ?></p>
                <?php if ($configured) : ?>
                    <p class="description pbfp-secret-status">
                        <span><?php esc_html_e('A secret is stored.', 'paybridge-for-plaid'); ?></span>
                        <button type="submit" class="button button-secondary" name="save" value="<?php echo esc_attr(self::RESET_SECRET_VALUE); ?>"><?php esc_html_e('Remove stored secret', 'paybridge-for-plaid'); ?></button>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string, mixed> $form_fields */
    public function generate_settings_html($form_fields = array(), $echo = true): string
    {
        $html = (string) parent::generate_settings_html($form_fields, false) . $this->integration_html();
        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped by the renderers.
        }
        return $html;
    }

    private function integration_html(): string
    {
        $settings = Settings::load();
        $webhook_url = rest_url(RestRoutes::NAMESPACE . '/webhook');
        $problems = GatewayAvailability::problems($settings, get_woocommerce_currency(), GatewayAvailability::site_uses_https());
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=' . ConnectionTester::ACTION), ConnectionTester::ACTION);
        $result = get_transient(ConnectionTester::TRANSIENT_PREFIX . get_current_user_id());
        ob_start();
        ?>
        <tr valign="top" class="pbfp-integration">
            <th scope="row" class="titledesc"><label for="pbfp-webhook-url"><?php esc_html_e('Webhook URL', 'paybridge-for-plaid'); ?></label></th>
            <td class="forminp">
                <div class="pbfp-copy-field">
                    <input type="text" readonly class="large-text code" id="pbfp-webhook-url" value="<?php echo esc_attr($webhook_url); ?>" />
                    <button type="button" class="button button-secondary" data-pbfp-copy="pbfp-webhook-url"><?php esc_html_e('Copy', 'paybridge-for-plaid'); ?></button>
                </div>
                <p class="description"><?php esc_html_e('In the Plaid Dashboard open Team Settings → Webhooks, add a webhook for “Transfer event” in the selected environment and paste this URL. Webhooks are cryptographically verified.', 'paybridge-for-plaid'); ?></p>
                <p class="screen-reader-text" data-pbfp-copy-status aria-live="polite"></p>
            </td>
        </tr>
        <tr valign="top" class="pbfp-integration">
            <th scope="row" class="titledesc"><?php esc_html_e('Configuration status', 'paybridge-for-plaid'); ?></th>
            <td class="forminp">
                <?php if (array() === $problems) : ?>
                    <p class="pbfp-status pbfp-status--ok"><?php esc_html_e('Ready: Pay by Bank can be offered at checkout.', 'paybridge-for-plaid'); ?></p>
                <?php else : ?>
                    <ul class="pbfp-status pbfp-status--warning">
                        <?php foreach ($problems as $problem) : ?>
                            <li><?php echo esc_html(GatewayAvailability::label($problem)); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p><a class="button button-secondary" href="<?php echo esc_url($test_url); ?>"><?php esc_html_e('Test connection', 'paybridge-for-plaid'); ?></a></p>
                <?php if (is_array($result)) : ?>
                    <p class="pbfp-status <?php echo 'connected' === ($result['status'] ?? '') ? 'pbfp-status--ok' : 'pbfp-status--warning'; ?>" role="status"><?php echo esc_html(ConnectionTester::message($result)); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }

    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }
        return array() === GatewayAvailability::problems(Settings::load(), $this->current_currency(), GatewayAvailability::site_uses_https());
    }

    /** @return array{result:string, redirect?:string} */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order) {
            wc_add_notice(__('We could not find this order.', 'paybridge-for-plaid'), 'error');
            return array('result' => 'failure');
        }
        try {
            ( new PaymentAccess() )->grant($order);
            $outcome = ( new Container() )->attempts()->start($order);
            $redirect = PaymentAttemptService::OUTCOME_TRANSFER === $outcome ? $order->get_checkout_order_received_url() : $order->get_checkout_payment_url(true);
            return array('result' => 'success', 'redirect' => $redirect);
        } catch (PaymentAttemptBusyException $exception) {
            wc_add_notice(__('Your bank payment is already being prepared. Please wait a few seconds and try again.', 'paybridge-for-plaid'), 'error');
        } catch (ConfigurationException $exception) {
            ( new Logger() )->log('error', 'gateway_misconfigured', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            wc_add_notice(__('Pay by Bank is temporarily unavailable. Please choose another payment method.', 'paybridge-for-plaid'), 'error');
        } catch (PayBridgeException $exception) {
            ( new Logger() )->log('warning', 'payment_start_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            wc_add_notice(__('We could not start the bank payment. Please try again or choose another payment method.', 'paybridge-for-plaid'), 'error');
        }
        return array('result' => 'failure');
    }

    private function current_currency(): string
    {
        $order_id = absint(get_query_var('order-pay'));
        if ($order_id > 0) {
            $order = wc_get_order($order_id);
            if ($order instanceof \WC_Order) {
                return (string) $order->get_currency();
            }
        }
        return get_woocommerce_currency();
    }
}
