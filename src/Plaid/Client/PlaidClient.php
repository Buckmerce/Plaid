<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Client;

use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Logging\Logger;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use PayBridge\Plaid\Plaid\Exception\PlaidNetworkException;
use PayBridge\Plaid\Plaid\PlaidEnvironment;

/**
 * The only component that performs HTTP calls to Plaid.
 *
 * Authentication uses the PLAID-CLIENT-ID / PLAID-SECRET headers documented at
 * https://plaid.com/docs/api/ (docs/api/PLAID_TRANSFER.md), JSON over HTTPS, with
 * the API version pinned through the Plaid-Version header.
 */
final class PlaidClient implements PlaidClientInterface
{
    public const API_VERSION = '2020-09-14';
    public const TIMEOUT_SECONDS = 30;

    /** Endpoints PayBridge is allowed to call. Anything else is a programming error. */
    private const ALLOWED_PATHS = array(
        '/transfer/intent/create',
        '/transfer/intent/get',
        '/link/token/create',
        '/transfer/get',
        '/transfer/cancel',
        '/transfer/event/sync',
        '/transfer/refund/create',
        '/transfer/refund/get',
        '/transfer/refund/cancel',
        '/transfer/configuration/get',
        '/transfer/ledger/get',
        '/webhook_verification_key/get',
        '/sandbox/transfer/simulate',
        '/sandbox/transfer/refund/simulate',
        '/sandbox/transfer/fire_webhook',
    );

    /** @var \Closure(string, array<string, mixed>): array{status:int, body:string} */
    private readonly \Closure $transport;

    /**
     * @param (callable(string, array<string, mixed>): array{status:int, body:string})|null $transport
     */
    public function __construct(
        private readonly PlaidEnvironment $environment,
        private readonly string $client_id,
        #[\SensitiveParameter] private readonly string $secret,
        ?callable $transport = null,
        private readonly ?Logger $logger = null
    ) {
        $this->transport = null === $transport ? \Closure::fromCallable(array(self::class, 'wordpress_transport')) : \Closure::fromCallable($transport);
    }

    public function environment(): PlaidEnvironment
    {
        return $this->environment;
    }

    public function post(string $path, array $body): PlaidResponse
    {
        if (! in_array($path, self::ALLOWED_PATHS, true)) {
            throw new ConfigurationException('Plaid endpoint is not allowed: ' . esc_html($path));
        }
        if (str_starts_with($path, '/sandbox/') && $this->environment->is_production()) {
            // Invariant: Production must never invoke Sandbox-only APIs.
            throw new ConfigurationException('Sandbox-only Plaid endpoints cannot be called in Production.');
        }
        if ('' === $this->client_id || '' === $this->secret) {
            throw new ConfigurationException('Plaid credentials are not configured.');
        }
        $json = json_encode(array() === $body ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new ConfigurationException('Plaid request could not be encoded as JSON.');
        }
        $args = array(
            'method' => 'POST',
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'PLAID-CLIENT-ID' => $this->client_id,
                'PLAID-SECRET' => $this->secret,
                'Plaid-Version' => self::API_VERSION,
                'User-Agent' => 'PayBridge-for-Plaid/' . (defined('PAYBRIDGE_PLAID_VERSION') ? PAYBRIDGE_PLAID_VERSION : 'dev'),
            ),
            'body' => $json,
        );
        $started = microtime(true);
        $result = ($this->transport)($this->environment->base_url() . $path, $args);
        $elapsed_ms = (int) round((microtime(true) - $started) * 1000);

        $status = $result['status'];
        $decoded = json_decode($result['body'], true, 512, JSON_BIGINT_AS_STRING);
        $request_id = is_array($decoded) && is_string($decoded['request_id'] ?? null) ? $decoded['request_id'] : '';

        if (200 === $status && is_array($decoded)) {
            $this->log('debug', 'plaid_request_succeeded', $path, $status, $request_id, $elapsed_ms);
            /** @var array<string, mixed> $decoded */
            return new PlaidResponse($decoded, $request_id);
        }
        if (is_array($decoded) && is_string($decoded['error_type'] ?? null) && is_string($decoded['error_code'] ?? null)) {
            $this->log('warning', 'plaid_request_failed', $path, $status, $request_id, $elapsed_ms, (string) $decoded['error_code']);
            throw new PlaidApiException(
                (int) $status,
                esc_html($decoded['error_type']),
                esc_html($decoded['error_code']),
                esc_html(is_string($decoded['error_message'] ?? null) ? $decoded['error_message'] : ''),
                esc_html(is_string($decoded['display_message'] ?? null) ? $decoded['display_message'] : ''),
                esc_html($request_id)
            );
        }
        $this->log('error', 'plaid_malformed_response', $path, $status, $request_id, $elapsed_ms);
        throw new PlaidMalformedResponseException(sprintf('Plaid returned an unexpected response (HTTP %d).', (int) $status), esc_html($request_id));
    }

    /**
     * @param array<string, mixed> $args
     * @return array{status:int, body:string}
     */
    public static function wordpress_transport(string $url, array $args): array
    {
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            // The request may have reached Plaid; callers treat this as ambiguous.
            throw new PlaidNetworkException('Plaid could not be reached: ' . esc_html((string) $response->get_error_code()));
        }
        return array(
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        );
    }

    private function log(string $level, string $event, string $path, int $status, string $request_id, int $elapsed_ms, string $error_code = ''): void
    {
        $this->logger?->log($level, $event, array(
            'environment' => $this->environment->name,
            'endpoint' => $path,
            'http_status' => $status,
            'request_id' => $request_id,
            'duration_ms' => $elapsed_ms,
            'error_code' => $error_code,
        ));
    }
}
