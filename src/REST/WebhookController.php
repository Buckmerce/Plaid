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
    /** Last verified webhook (time, type, code, environment, outcome) for diagnostics. */
    public const LAST_WEBHOOK_OPTION = 'paybridge_plaid_last_webhook';
    /** Last rejected webhook (time, reason, HTTP status); written at most once a minute. */
    public const LAST_REJECTION_OPTION = 'paybridge_plaid_last_webhook_rejection';
    private const REJECTION_RECORD_INTERVAL_SECONDS = 60;

    public function handle(\WP_REST_Request $request): \WP_REST_Response
    {
        $logger = new Logger();
        $raw_body = (string) $request->get_body();
        $jwt = (string) $request->get_header('plaid_verification');
        if (strlen($raw_body) > WebhookVerificationService::MAX_BODY_BYTES) {
            $logger->security('webhook_rejected', array('reason' => 'oversized_body', 'bytes' => strlen($raw_body)));
            self::record_rejection('oversized_body', 413);
            return new \WP_REST_Response(array('error' => 'invalid_webhook'), 413);
        }
        $settings = Settings::load();
        try {
            $verifier = ( new Container($settings) )->webhook_verifier();
            $body = $verifier->verify($raw_body, $jwt);
        } catch (ConfigurationException $exception) {
            $logger->security('webhook_rejected', array('reason' => 'not_configured'));
            self::record_rejection('not_configured', 503);
            return new \WP_REST_Response(array('error' => 'not_configured'), 503);
        } catch (WebhookVerificationException $exception) {
            $logger->security('webhook_rejected', array('reason' => $exception->reason, 'body_sha256' => Logger::fingerprint($raw_body)));
            $status = in_array($exception->reason, array('key_unavailable', 'key_fetch_rate_limited'), true) ? 503 : 401;
            self::record_rejection($exception->reason, $status);
            return new \WP_REST_Response(array('error' => 'invalid_webhook'), $status);
        }

        $type = is_string($body['webhook_type'] ?? null) ? $body['webhook_type'] : '';
        $code = is_string($body['webhook_code'] ?? null) ? $body['webhook_code'] : '';
        $environment = is_string($body['environment'] ?? null) ? $body['environment'] : '';
        if ('TRANSFER' === $type && 'TRANSFER_EVENTS_UPDATE' === $code) {
            if ($environment !== $settings->environment_name()) {
                $logger->log('warning', 'webhook_environment_mismatch', array('environment' => $environment));
                self::record_verified($type, $code, $environment, 'environment_mismatch');
                return new \WP_REST_Response(array('received' => true), 200);
            }
            if (! Scheduler::enqueue_event_sync()) {
                // Reconciliation still recovers these events; ask Plaid to retry the notification.
                $logger->log('error', 'event_sync_enqueue_failed', array('environment' => $environment));
                self::record_verified($type, $code, $environment, 'enqueue_failed');
                return new \WP_REST_Response(array('error' => 'enqueue_failed'), 503);
            }
            $logger->log('info', 'webhook_transfer_events_update', array('environment' => $environment));
            self::record_verified($type, $code, $environment, 'event_sync_queued');
            do_action('paybridge_plaid_webhook_processed', $type, $code, $environment);
            return new \WP_REST_Response(array('received' => true), 200);
        }
        $logger->log('debug', 'webhook_ignored', array('webhook_type' => substr($type, 0, 40), 'webhook_code' => substr($code, 0, 60)));
        self::record_verified($type, $code, $environment, 'ignored');
        return new \WP_REST_Response(array('received' => true), 200);
    }

    private static function record_verified(string $type, string $code, string $environment, string $outcome): void
    {
        update_option(self::LAST_WEBHOOK_OPTION, array(
            'at' => gmdate('c'),
            'type' => self::code($type, 40),
            'code' => self::code($code, 60),
            'environment' => self::code($environment, 16),
            'outcome' => $outcome,
        ), false);
    }

    /** Throttled so unauthenticated junk traffic cannot turn into a stream of option writes. */
    private static function record_rejection(string $reason, int $status): void
    {
        $last = get_option(self::LAST_REJECTION_OPTION, array());
        $last_at = is_array($last) ? strtotime((string) ($last['at'] ?? '')) : false;
        if (false !== $last_at && $last_at > time() - self::REJECTION_RECORD_INTERVAL_SECONDS) {
            return;
        }
        update_option(self::LAST_REJECTION_OPTION, array('at' => gmdate('c'), 'reason' => self::code($reason, 40), 'status' => $status), false);
    }

    private static function code(string $value, int $length): string
    {
        return substr(preg_replace('/[^A-Za-z0-9_\-]/', '', $value) ?? '', 0, $length);
    }
}
