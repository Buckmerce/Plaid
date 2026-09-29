<?php

declare(strict_types=1);

namespace PayBridge\Plaid\REST;

use PayBridge\Plaid\Background\Scheduler;
use PayBridge\Plaid\Container;
use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Plaid\Exception\WebhookVerificationException;
use PayBridge\Plaid\Plaid\Webhook\WebhookVerificationService;
use PayBridge\Plaid\Settings\Settings;

/**
 * POST /wp-json/paybridge-for-plaid/v1/webhook
 *
 * Nothing about the request is trusted until WebhookVerificationService has
 * verified the ES256 JWT and the raw-body hash. A verified
 * TRANSFER_EVENTS_UPDATE only enqueues /transfer/event/sync; the webhook body
 * itself never changes payment state.
 */
final class WebhookController
{
    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $logger = new Logger();
        $raw_body = (string) $request->get_body();
        $jwt = (string) $request->get_header('plaid_verification');
        if (strlen($raw_body) > WebhookVerificationService::MAX_BODY_BYTES) {
            $logger->security('webhook_rejected', array('reason' => 'oversized_body', 'bytes' => strlen($raw_body)));
            return new \WP_REST_Response(array('error' => 'invalid_webhook'), 413);
        }
        $settings = Settings::load();
        try {
            $verifier = ( new Container($settings) )->webhook_verifier();
            $body = $verifier->verify($raw_body, $jwt);
        } catch (ConfigurationException $exception) {
            $logger->security('webhook_rejected', array('reason' => 'not_configured'));
            return new \WP_REST_Response(array('error' => 'not_configured'), 503);
        } catch (WebhookVerificationException $exception) {
            $logger->security('webhook_rejected', array('reason' => $exception->reason, 'body_sha256' => Logger::fingerprint($raw_body)));
            $retryable = in_array($exception->reason, array('key_unavailable', 'key_fetch_rate_limited'), true);
            return new \WP_REST_Response(array('error' => 'invalid_webhook'), $retryable ? 503 : 401);
        }

        $type = is_string($body['webhook_type'] ?? null) ? $body['webhook_type'] : '';
        $code = is_string($body['webhook_code'] ?? null) ? $body['webhook_code'] : '';
        $environment = is_string($body['environment'] ?? null) ? $body['environment'] : '';
        if ('TRANSFER' === $type && 'TRANSFER_EVENTS_UPDATE' === $code) {
            if ($environment !== $settings->environment_name()) {
                $logger->log('warning', 'webhook_environment_mismatch', array('environment' => $environment));
                return new \WP_REST_Response(array('received' => true), 200);
            }
            if (! Scheduler::enqueue_event_sync()) {
                // Reconciliation still recovers these events; ask Plaid to retry the notification.
                $logger->log('error', 'event_sync_enqueue_failed', array('environment' => $environment));
                return new \WP_REST_Response(array('error' => 'enqueue_failed'), 503);
            }
            $logger->log('info', 'webhook_transfer_events_update', array('environment' => $environment));
            do_action('paybridge_plaid_webhook_processed', $type, $code, $environment);
            return new \WP_REST_Response(array('received' => true), 200);
        }
        $logger->log('debug', 'webhook_ignored', array('webhook_type' => substr($type, 0, 40), 'webhook_code' => substr($code, 0, 60)));
        return new \WP_REST_Response(array('received' => true), 200);
    }
}
