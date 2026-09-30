<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Gateway;

use PayBridge\Plaid\Admin\ConfigurationStatus;
use PayBridge\Plaid\Admin\ConnectionTester;
use PayBridge\Plaid\Admin\DiagnosticsPage;
use PayBridge\Plaid\Checkout\PaymentAccess;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Exception\MissingAccountHolderNameException;
use PayBridge\Plaid\Exception\PaymentAttemptBusyException;
use PayBridge\Plaid\Exception\PayBridgeException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Payment\PaymentAttemptService;
use PayBridge\Plaid\Plaid\TransferIntent\TransferIntentRequest;
use PayBridge\Plaid\REST\RestRoutes;
use PayBridge\Plaid\Refund\WooRefundContext;
use PayBridge\Plaid\Settings\Settings;

/**
 * WooCommerce adapter: settings, availability, process_payment() and process_refund()
 * coordination. It contains no Plaid wire-format code.
 */
final class PayBridgeGateway extends \WC_Payment_Gateway
{
    private const RESET_SECRET_VALUE = 'pbfp_reset_secret';

    public function __construct()
    {
        $this->id = Settings::GATEWAY_ID;
        $this->method_title = __('PayBridge for Plaid', 'paybridge-for-plaid');
        $this->method_description = __('Pay by Bank through Plaid Transfer. Customers authorize a one-time ACH debit in Plaid Transfer UI; orders are updated from verified Plaid transfer events, and refunds are sent back to the customer\'s bank through Plaid.', 'paybridge-for-plaid');
        $this->has_fields = false;
        $this->supports = array('products', 'refunds');
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
            'general' => array('title' => __('General', 'paybridge-for-plaid'), 'type' => 'title', 'description' => __('Disabling Pay by Bank only hides it at checkout. Existing bank payments, returns and refunds keep being monitored.', 'paybridge-for-plaid')),
            'enabled' => array('title' => __('Enable/Disable', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Offer Pay by Bank at checkout', 'paybridge-for-plaid'), 'default' => 'no'),
            'title' => array('title' => __('Title', 'paybridge-for-plaid'), 'type' => 'text', 'default' => __('Pay by Bank', 'paybridge-for-plaid'), 'desc_tip' => true, 'description' => __('Payment method name shown at checkout.', 'paybridge-for-plaid')),
            'description' => array('title' => __('Description', 'paybridge-for-plaid'), 'type' => 'textarea', 'default' => __('Securely pay directly from your bank account.', 'paybridge-for-plaid'), 'desc_tip' => true, 'description' => __('Shown below the payment method at checkout.', 'paybridge-for-plaid')),
            'plaid' => array('title' => __('Plaid connection', 'paybridge-for-plaid'), 'type' => 'title', 'description' => __('API keys are in the Plaid Dashboard under Developers → Keys. Plaid Transfer must be enabled for your team. Changing the Client ID or environment is refused while Production payments are still monitored.', 'paybridge-for-plaid')),
            'environment' => array('title' => __('Environment', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'sandbox', 'options' => array('sandbox' => __('Sandbox (test, no real money)', 'paybridge-for-plaid'), 'production' => __('Production (real money)', 'paybridge-for-plaid')), 'description' => __('Production moves real money and requires HTTPS.', 'paybridge-for-plaid')),
            'client_id' => array('title' => __('Client ID', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'custom_attributes' => array('autocomplete' => 'off', 'spellcheck' => 'false')),
            'secret' => array('title' => __('Secret', 'paybridge-for-plaid'), 'type' => 'pbfp_secret', 'default' => '', 'description' => __('Use the secret of the selected environment. Leave blank when saving to keep the stored secret.', 'paybridge-for-plaid')),
            'funding_account_id' => array('title' => __('Funding Account ID (optional)', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'description' => __('Leave empty when your Plaid Transfer account uses Plaid Ledger (the default). Only accounts without a Ledger configure a funding account ID here.', 'paybridge-for-plaid')),
            'pay_by_bank' => array('title' => __('Pay by Bank', 'paybridge-for-plaid'), 'type' => 'title'),
            'link_customization_name' => array('title' => __('Link customization name', 'paybridge-for-plaid'), 'type' => 'text', 'default' => '', 'description' => __('Required for Production. In the Plaid Dashboard open Link → Link Customization, create a customization with Account Select set to “Enabled for one account” (its language must match your store language), publish it and enter its name here. Sandbox uses Plaid\'s default customization when empty.', 'paybridge-for-plaid')),
            'statement_descriptor' => array('title' => __('Bank statement description', 'paybridge-for-plaid'), 'type' => 'text', 'default' => Settings::DEFAULT_STATEMENT_DESCRIPTOR, 'custom_attributes' => array('maxlength' => (string) Settings::STATEMENT_DESCRIPTOR_MAX, 'autocomplete' => 'off'), 'description' => __('Shown on the customer\'s bank statement after the company name Plaid has on file. Use a stable word that describes the purpose, such as PAYMENT or ORDER: recognizable descriptions reduce "unrecognized payment" disputes and returns. Letters, digits and spaces only, at most 10 characters; never put order numbers or personal data here.', 'paybridge-for-plaid')),
            'network' => array('title' => __('Payment network', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'same-day-ach', 'options' => array('same-day-ach' => __('Same Day ACH', 'paybridge-for-plaid'), 'ach' => __('Standard ACH', 'paybridge-for-plaid')), 'description' => __('Same Day ACH payments made after Plaid\'s cutoff are sent as Standard ACH automatically.', 'paybridge-for-plaid')),
            'confirmation_state' => array('title' => __('Mark order paid when', 'paybridge-for-plaid'), 'type' => 'select', 'default' => 'funds_available', 'options' => array('funds_available' => __('Funds are available (recommended)', 'paybridge-for-plaid'), 'settled' => __('Transfer is settled', 'paybridge-for-plaid')), 'description' => __('Until then orders stay On hold. ACH debits can still be returned later; returns are always recorded and flagged.', 'paybridge-for-plaid')),
            'background' => array('title' => __('Background processing', 'paybridge-for-plaid'), 'type' => 'title', 'description' => __('PayBridge follows every bank payment until its ACH return window closes, using verified Plaid webhooks, Plaid transfer events and a reconciliation job every 15 minutes (WooCommerce Action Scheduler). This cannot be turned off while payments exist.', 'paybridge-for-plaid')),
            'advanced' => array('title' => __('Advanced and logging', 'paybridge-for-plaid'), 'type' => 'title'),
            'debug' => array('title' => __('Debug logging', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Write redacted debug logs (WooCommerce → Status → Logs)', 'paybridge-for-plaid'), 'default' => 'no'),
            'delete_data_on_uninstall' => array('title' => __('Uninstall cleanup', 'paybridge-for-plaid'), 'type' => 'checkbox', 'label' => __('Delete PayBridge settings, event and refund history and tables when the plugin is deleted', 'paybridge-for-plaid'), 'default' => 'no', 'description' => __('Order payment records stay on the orders for auditing. Leave this off while payments may still be returned.', 'paybridge-for-plaid')),
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
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce before calling this method.
        $reset = isset($_POST['save']) && self::RESET_SECRET_VALUE === sanitize_text_field(wp_unslash($_POST['save']));
        if ($reset) {
            $_POST[$field] = '';
        } elseif (isset($_POST[$field]) && '' === sanitize_text_field(wp_unslash($_POST[$field])) && is_array($stored) && is_string($stored['secret'] ?? null)) {
            $_POST[$field] = $stored['secret'];
        }
        $client_field = $this->get_field_key('client_id');
        if (isset($_POST[$client_field])) {
            $_POST[$client_field] = preg_replace('/[^A-Za-z0-9]/', '', sanitize_text_field(wp_unslash($_POST[$client_field])));
        }
        foreach (array('funding_account_id', 'link_customization_name') as $key) {
            $key_field = $this->get_field_key($key);
            if (isset($_POST[$key_field])) {
                $_POST[$key_field] = substr(preg_replace('/[^A-Za-z0-9 _\-]/', '', sanitize_text_field(wp_unslash($_POST[$key_field]))) ?? '', 0, 100);
            }
        }
        $descriptor_field = $this->get_field_key('statement_descriptor');
        if (isset($_POST[$descriptor_field])) {
            $descriptor = Settings::normalize_statement_descriptor(sanitize_text_field(wp_unslash($_POST[$descriptor_field])));
            $_POST[$descriptor_field] = '' === $descriptor ? Settings::DEFAULT_STATEMENT_DESCRIPTOR : $descriptor;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing
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
                <input type="password" autocomplete="new-password" class="input-text regular-input" name="<?php echo esc_attr($field_key); ?>" id="<?php echo esc_attr($field_key); ?>" value="" placeholder="<?php echo esc_attr($configured ? '••••••••••••' : ''); ?>" aria-describedby="<?php echo esc_attr($field_key); ?>-description" />
                <p class="description" id="<?php echo esc_attr($field_key); ?>-description"><?php echo esc_html((string) ($data['description'] ?? '')); ?></p>
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
        $html = $this->integration_html() . (string) parent::generate_settings_html($form_fields, false);
        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every dynamic value is escaped by the renderers.
        }
        return $html;
    }

    private function integration_html(): string
    {
        $settings = Settings::load();
        $webhook_url = rest_url(RestRoutes::NAMESPACE . '/webhook');
        $status = ConfigurationStatus::evaluate($settings);
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=' . ConnectionTester::ACTION), ConnectionTester::ACTION);
        $result = get_transient(ConnectionTester::TRANSIENT_PREFIX . get_current_user_id());
        ob_start();
        ?>
        <tr valign="top" class="pbfp-integration"><td colspan="2" class="forminp">
        <div class="pbfp-status-panel pbfp-status-panel--<?php echo esc_attr($status['level']); ?>" role="region" aria-labelledby="pbfp-status-heading">
            <h3 id="pbfp-status-heading">
                <?php esc_html_e('Status', 'paybridge-for-plaid'); ?>:
                <span class="pbfp-badge pbfp-badge--<?php echo esc_attr($status['level']); ?>"><?php echo esc_html($status['label']); ?></span>
                <span class="pbfp-badge pbfp-badge--<?php echo esc_attr($settings->is_production() ? 'production' : 'sandbox'); ?>"><?php echo esc_html($settings->is_production() ? __('Production', 'paybridge-for-plaid') : __('Sandbox', 'paybridge-for-plaid')); ?></span>
            </h3>
            <ul class="pbfp-checklist">
                <?php foreach ($status['checks'] as $check) : ?>
                    <li class="pbfp-check pbfp-check--<?php echo esc_attr($check['result']); ?>">
                        <span class="pbfp-check__result"><?php echo esc_html(ConfigurationStatus::result_label($check['result'])); ?></span>
                        <span class="pbfp-check__label"><?php echo esc_html($check['label']); ?></span>
                        <?php if ('' !== $check['help']) : ?>
                            <span class="pbfp-check__help"><?php echo esc_html($check['help']); ?></span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p>
                <a class="button button-secondary" href="<?php echo esc_url($test_url); ?>"><?php esc_html_e('Test connection', 'paybridge-for-plaid'); ?></a>
                <a class="button button-link" href="<?php echo esc_url(DiagnosticsPage::url()); ?>"><?php esc_html_e('Open diagnostics', 'paybridge-for-plaid'); ?></a>
            </p>
            <?php if (is_array($result)) : ?>
                <p class="pbfp-status <?php echo 'connected' === ($result['status'] ?? '') ? 'pbfp-status--ok' : 'pbfp-status--warning'; ?>" role="status"><?php echo esc_html(ConnectionTester::message($result)); ?></p>
            <?php endif; ?>
            <div class="pbfp-copy-field">
                <label for="pbfp-webhook-url"><strong><?php esc_html_e('Webhook URL', 'paybridge-for-plaid'); ?></strong></label>
                <input type="text" readonly class="large-text code" id="pbfp-webhook-url" value="<?php echo esc_attr($webhook_url); ?>" aria-describedby="pbfp-webhook-help" />
                <button type="button" class="button button-secondary" data-pbfp-copy="pbfp-webhook-url"><?php esc_html_e('Copy', 'paybridge-for-plaid'); ?></button>
            </div>
            <p class="description" id="pbfp-webhook-help"><?php esc_html_e('In the Plaid Dashboard open Team Settings → Webhooks, add a webhook for “Transfer event” in the selected environment and paste this URL. Webhooks are cryptographically verified.', 'paybridge-for-plaid'); ?></p>
            <p class="screen-reader-text" data-pbfp-copy-status aria-live="polite"></p>
        </div>
        </td></tr>
        <?php
        return (string) ob_get_clean();
    }

    /** WooCommerce opens the settings instead of enabling the gateway while no credentials exist. */
    public function needs_setup(): bool
    {
        return ! Settings::load()->has_credentials();
    }

    public function is_available(): bool
    {
        if (! parent::is_available()) {
            return false;
        }
        return array() === GatewayAvailability::problems(Settings::load(), $this->current_currency(), GatewayAvailability::site_uses_https());
    }

    /**
     * Classic Checkout: runs before the order is created. Checkout Blocks validates the saved
     * order in process_payment() instead, because its billing fields are not in $_POST.
     */
    public function validate_fields(): bool
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before gateway validation.
        if (! isset($_POST['billing_first_name']) && ! isset($_POST['billing_last_name'])) {
            return true;
        }
        $first = sanitize_text_field(wp_unslash((string) ($_POST['billing_first_name'] ?? '')));
        $last = sanitize_text_field(wp_unslash((string) ($_POST['billing_last_name'] ?? '')));
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ('' === TransferIntentRequest::legal_name($first, $last)) {
            wc_add_notice(self::legal_name_message(), 'error');
            return false;
        }
        return true;
    }

    public static function legal_name_message(): string
    {
        return __('Please enter the account holder\'s legal first and last name in the billing details before paying by bank.', 'paybridge-for-plaid');
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
        } catch (MissingAccountHolderNameException $exception) {
            wc_add_notice(self::legal_name_message(), 'error');
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

    /**
     * Only payments that can actually be refunded through Plaid show "Refund via Pay by Bank".
     * Uses local data only, so rendering the order screen never calls Plaid.
     *
     * @param \WC_Order|false|null $order
     */
    public function can_refund_order($order): bool
    {
        if (! $order instanceof \WC_Order || ! parent::can_refund_order($order)) {
            return false;
        }
        return ( new Container() )->refunds()->eligibility($order)->allowed;
    }

    /**
     * WooCommerce refund → Plaid /transfer/refund/create (ADR-0016). WooCommerce has already
     * saved its refund object; on error it deletes it again and shows the message to the admin.
     *
     * @param int        $order_id
     * @param float|null $amount
     * @param string     $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        $order = wc_get_order($order_id);
        if (! $order instanceof \WC_Order || Settings::GATEWAY_ID !== $order->get_payment_method()) {
            return new \WP_Error('paybridge_refund_invalid_order', __('This order was not paid with Pay by Bank.', 'paybridge-for-plaid'));
        }
        if (null === $amount || '' === (string) $amount) {
            return new \WP_Error('paybridge_refund_amount', __('Enter the amount to refund.', 'paybridge-for-plaid'));
        }
        $amount = (string) $amount;
        try {
            $outcome = ( new Container() )->refunds()->refund($order, WooRefundContext::for_order($order, $amount), $amount, (string) $reason);
        } catch (ConfigurationException $exception) {
            return new \WP_Error('paybridge_refund_unavailable', __('Pay by Bank refunds are unavailable because the Plaid connection is not configured.', 'paybridge-for-plaid'));
        } catch (PayBridgeException $exception) {
            ( new Logger() )->log('error', 'refund_failed_unexpectedly', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            return new \WP_Error('paybridge_refund_failed', __('The refund could not be processed. No refund was sent to Plaid; see the PayBridge logs.', 'paybridge-for-plaid'));
        }
        return $outcome->ok ? true : new \WP_Error('paybridge_refund_failed', $outcome->message);
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
