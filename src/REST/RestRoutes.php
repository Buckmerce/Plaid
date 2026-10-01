<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\REST;

use Buckmerce\Plaid\Checkout\PaymentAccess;
use Buckmerce\Plaid\Container;
use Buckmerce\Plaid\Exception\ConfigurationException;
use Buckmerce\Plaid\Exception\MissingAccountHolderNameException;
use Buckmerce\Plaid\Exception\PaymentAttemptBusyException;
use Buckmerce\Plaid\Exception\ReturnedPaymentRetryException;
use Buckmerce\Plaid\Gateway\BuckmerceGateway;
use Buckmerce\Plaid\Exception\BuckmerceException;
use Buckmerce\Plaid\Logging\Logger;
use Buckmerce\Plaid\Payment\CompletionResult;
use Buckmerce\Plaid\Payment\ReturnRetryPolicy;

/**
 * REST routes. Customer routes require order key + ownership + a payment nonce;
 * the webhook route is public at the HTTP layer and authenticated
 * cryptographically inside WebhookController.
 */
final class RestRoutes
{
    public const NAMESPACE = 'buckmerce-plaid/v1';
    /** Each Link token is a Plaid API call; a real customer needs a handful per order. */
    private const LINK_TOKENS_PER_WINDOW = 15;
    /** Each completion check is a Plaid /transfer/intent/get call. */
    private const COMPLETIONS_PER_WINDOW = 40;
    private const RATE_WINDOW_SECONDS = 600;

    public function __construct(private readonly PaymentAccess $access = new PaymentAccess())
    {
    }

    public function register(): void
    {
        add_action('rest_api_init', array($this, 'routes'));
    }

    public function routes(): void
    {
        $order_args = array(
            'order_id' => array('type' => 'integer', 'required' => true, 'minimum' => 1),
            'order_key' => array('type' => 'string', 'required' => true, 'pattern' => '^wc_order_[A-Za-z0-9]{1,40}$'),
            'payment_nonce' => array('type' => 'string', 'required' => true, 'pattern' => '^[a-f0-9]{10}$'),
        );
        register_rest_route(self::NAMESPACE, '/link-token', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'link_token'),
            'permission_callback' => array($this, 'authorize_payer'),
            'args' => $order_args,
        ));
        register_rest_route(self::NAMESPACE, '/complete', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array($this, 'complete'),
            'permission_callback' => array($this, 'authorize_payer'),
            'args' => $order_args,
        ));
        register_rest_route(self::NAMESPACE, '/webhook', array(
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => array(new WebhookController(), 'handle'),
            // Authenticated by Plaid-Verification JWT inside the callback (never before verification).
            'permission_callback' => '__return_true',
            'args' => array(),
        ));
    }

    public function authorize_payer(\WP_REST_Request $request): bool|\WP_Error
    {
        $order = wc_get_order((int) $request->get_param('order_id'));
        $nonce = (string) $request->get_param('payment_nonce');
        $this->access->ensure_session();
        if (
            ! $order instanceof \WC_Order
            || ! $this->access->can_access($order, (string) $request->get_param('order_key'))
            || false === wp_verify_nonce($nonce, PaymentAccess::nonce_action($order->get_id()))
        ) {
            // Deliberately indistinguishable: no order existence or ownership oracle.
            return new \WP_Error('buckmerce_forbidden', __('This payment session is not valid.', 'buckmerce-plaid'), array('status' => 403));
        }
        return true;
    }

    public function link_token(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $order = wc_get_order((int) $request->get_param('order_id'));
        if (! $order instanceof \WC_Order) {
            return new \WP_Error('buckmerce_forbidden', __('This payment session is not valid.', 'buckmerce-plaid'), array('status' => 403));
        }
        if (! $this->consume_rate_limit('link_token', $order->get_id(), self::LINK_TOKENS_PER_WINDOW)) {
            return new \WP_Error('buckmerce_rate_limited', __('Too many attempts. Please wait a few minutes and try again.', 'buckmerce-plaid'), array('status' => 429));
        }
        try {
            $token = ( new Container() )->attempts()->issue_link_token($order);
        } catch (PaymentAttemptBusyException $exception) {
            return new \WP_Error('buckmerce_busy', __('Your bank payment is being prepared. Please try again in a few seconds.', 'buckmerce-plaid'), array('status' => 409));
        } catch (MissingAccountHolderNameException $exception) {
            return new \WP_Error('buckmerce_missing_name', BuckmerceGateway::legal_name_message() . ' ' . __('If you cannot change it, please contact the store.', 'buckmerce-plaid'), array('status' => 400));
        } catch (ReturnedPaymentRetryException $exception) {
            return new \WP_Error('buckmerce_not_payable', ReturnRetryPolicy::customer_message(), array('status' => 409));
        } catch (ConfigurationException $exception) {
            ( new Logger() )->log('warning', 'link_token_unavailable', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            return new \WP_Error('buckmerce_unavailable', __('Pay by Bank is not available for this order right now. Please contact the store or choose another payment method.', 'buckmerce-plaid'), array('status' => 503));
        } catch (BuckmerceException $exception) {
            ( new Logger() )->log('warning', 'link_token_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            return new \WP_Error('buckmerce_unavailable', __('The bank payment could not be started. Please try again.', 'buckmerce-plaid'), array('status' => 503));
        }
        if (null === $token) {
            return new \WP_REST_Response(array('status' => CompletionResult::SUBMITTED, 'redirect' => $order->get_checkout_order_received_url()), 200);
        }
        $response = new \WP_REST_Response(array('status' => 'ready', 'link_token' => $token->token, 'expiration' => $token->expiration), 200);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function complete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $order = wc_get_order((int) $request->get_param('order_id'));
        if (! $order instanceof \WC_Order) {
            return new \WP_Error('buckmerce_forbidden', __('This payment session is not valid.', 'buckmerce-plaid'), array('status' => 403));
        }
        if (! $this->consume_rate_limit('complete', $order->get_id(), self::COMPLETIONS_PER_WINDOW)) {
            return new \WP_Error('buckmerce_rate_limited', __('Too many attempts. Please wait a few minutes and try again.', 'buckmerce-plaid'), array('status' => 429));
        }
        try {
            $result = ( new Container() )->completion()->complete($order);
        } catch (PaymentAttemptBusyException $exception) {
            return new \WP_REST_Response(array('status' => CompletionResult::UNVERIFIED), 200);
        } catch (BuckmerceException $exception) {
            ( new Logger() )->log('warning', 'completion_failed', array('order_id' => $order->get_id(), 'error_code' => Logger::fingerprint($exception->getMessage())));
            return new \WP_Error('buckmerce_unavailable', __('The payment could not be confirmed. Please try again.', 'buckmerce-plaid'), array('status' => 503));
        }
        $body = array('status' => $result->status, 'reason' => preg_replace('/[^A-Z_]/', '', strtoupper($result->reason_code)));
        if (CompletionResult::SUBMITTED === $result->status) {
            $body['redirect'] = $order->get_checkout_order_received_url();
        }
        return new \WP_REST_Response($body, 200);
    }

    private function consume_rate_limit(string $bucket, int $order_id, int $limit): bool
    {
        $key = 'bmfp_rl_' . $bucket . '_' . $order_id;
        $count = get_transient($key);
        $count = is_int($count) ? $count : 0;
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW_SECONDS);
        return true;
    }
}
