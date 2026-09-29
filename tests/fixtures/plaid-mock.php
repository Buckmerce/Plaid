<?php
/**
 * Deterministic Plaid API double for disposable integration/browser sites (MU plugin).
 *
 * It intercepts only sandbox.plaid.com / production.plaid.com requests through
 * WordPress' pre_http_request filter, validates request bodies against the
 * documented contracts (docs/api) and mirrors Plaid Sandbox behavior, including
 * the Plaid Ledger rejection of funding_account_id and the $11.11 / $22.22 /
 * $33.33 transfer scenarios. State lives in a WordPress option so separate PHP
 * processes share it. It is inert unless PAYBRIDGE_PLAID_TEST_DATABASE is true.
 */

declare(strict_types=1);

if (! defined('PAYBRIDGE_PLAID_TEST_DATABASE') || true !== PAYBRIDGE_PLAID_TEST_DATABASE) {
    return;
}

// Never use the host mailer on a disposable site; record mails for assertions.
add_filter('pre_wp_mail', static function ($short_circuit, array $atts) {
    $mails = get_option('pbfp_test_mails', array());
    $mails = is_array($mails) ? $mails : array();
    $mails[] = array('to' => $atts['to'] ?? '', 'subject' => $atts['subject'] ?? '');
    update_option('pbfp_test_mails', $mails, false);
    return true;
}, 10, 2);

final class PayBridge_Test_Plaid_Mock
{
    public const STATE = 'pbfp_test_plaid_state';
    public const KEY = 'pbfp_test_plaid_key';
    public const KID = 'pbfp-test-kid-1';

    /** @return array<string, mixed> */
    public static function state(): array
    {
        wp_cache_delete(self::STATE, 'options');
        $state = get_option(self::STATE, array());
        return is_array($state) ? $state + self::empty_state() : self::empty_state();
    }

    /** @return array<string, mixed> */
    private static function empty_state(): array
    {
        return array('intents' => array(), 'transfers' => array(), 'tokens' => array(), 'events' => array(), 'next_event_id' => 1, 'calls' => array(), 'fail' => array());
    }

    /** @param array<string, mixed> $state */
    public static function save(array $state): void
    {
        update_option(self::STATE, $state, false);
        wp_cache_delete(self::STATE, 'options');
    }

    public static function reset(): void
    {
        delete_option(self::STATE);
        delete_option('pbfp_test_mails');
        wp_cache_delete(self::STATE, 'options');
    }

    /** Queue a failure for the next call to $path: timeout|timeout_after_create|server_error|reject|delay:<seconds>|key_fail|amount:<value>. */
    public static function fail_next(string $path, string $mode): void
    {
        $state = self::state();
        $state['fail'][$path][] = $mode;
        self::save($state);
    }

    /** @return list<array{path:string, body:array<string, mixed>}> */
    public static function calls(?string $path = null): array
    {
        $calls = self::state()['calls'];
        return array_values(null === $path ? $calls : array_filter($calls, static fn (array $call): bool => $call['path'] === $path));
    }

    /** @return array<string, mixed>|false|WP_Error */
    public static function handle($preempt, array $args, string $url)
    {
        if (! preg_match('#^https://(sandbox|production)\.plaid\.com(/[a-z_/]+)$#', $url, $match)) {
            return $preempt;
        }
        $environment = $match[1];
        $path = $match[2];
        $body = json_decode((string) ($args['body'] ?? ''), true);
        $body = is_array($body) ? $body : array();
        $headers = is_array($args['headers'] ?? null) ? $args['headers'] : array();
        if ('' === (string) ($headers['PLAID-CLIENT-ID'] ?? '') || '' === (string) ($headers['PLAID-SECRET'] ?? '')) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_API_KEYS', 'invalid client_id or secret provided');
        }
        if ('2020-09-14' !== ($headers['Plaid-Version'] ?? '')) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_HEADERS', 'unexpected Plaid-Version');
        }
        foreach (array('client_id', 'secret') as $credential) {
            if (array_key_exists($credential, $body)) {
                throw new RuntimeException('Credentials must be sent in headers only.');
            }
        }

        $state = self::state();
        $state['calls'][] = array('path' => $path, 'body' => $body, 'environment' => $environment);
        $failure = '';
        if (! empty($state['fail'][$path])) {
            $failure = (string) array_shift($state['fail'][$path]);
        }
        self::save($state);

        if (str_starts_with($failure, 'delay:')) {
            sleep((int) substr($failure, 6));
            $failure = '';
        }
        if ('timeout' === $failure) {
            return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        }
        if ('server_error' === $failure) {
            return self::error(500, 'API_ERROR', 'INTERNAL_SERVER_ERROR', 'an unexpected error occurred');
        }
        if ('reject' === $failure) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'rejected by test fixture');
        }
        if ('key_fail' === $failure) {
            return new WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
        }

        switch ($path) {
            case '/transfer/intent/create':
                $response = self::intent_create($body, $environment, $failure);
                return 'timeout_after_create' === $failure ? new WP_Error('http_request_failed', 'cURL error 28: response lost after create') : $response;
            case '/transfer/intent/get':
                return self::intent_get($body);
            case '/link/token/create':
                return self::link_token_create($body);
            case '/transfer/get':
                return self::transfer_get($body);
            case '/transfer/event/sync':
                return self::event_sync($body);
            case '/transfer/configuration/get':
                return self::ok(array('iso_currency_code' => 'USD', 'max_single_transfer_amount' => ''));
            case '/transfer/ledger/get':
                return self::ok(array('balance' => array('available' => '100.00', 'pending' => '0.00'), 'is_default' => true, 'ledger_id' => 'ledger-test', 'name' => 'default'));
            case '/webhook_verification_key/get':
                return self::verification_key($body);
            case '/sandbox/transfer/simulate':
                $transfer_id = (string) ($body['transfer_id'] ?? '');
                if (! isset(self::state()['transfers'][$transfer_id])) {
                    return self::error(400, 'INVALID_INPUT', 'INVALID_TRANSFER_ID', 'unknown transfer');
                }
                self::add_event($transfer_id, (string) ($body['event_type'] ?? ''), (string) ($body['failure_reason']['failure_code'] ?? ''));
                return self::ok(array());
        }
        return self::error(404, 'INVALID_REQUEST', 'NOT_FOUND', 'unknown endpoint ' . $path);
    }

    /** @param array<string, mixed> $body */
    private static function intent_create(array $body, string $environment, string $failure): array
    {
        if ('PAYMENT' !== ($body['mode'] ?? null)) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'mode must be PAYMENT');
        }
        if (array_key_exists('funding_account_id', $body)) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'funding_account_id cannot be set when ledger is enabled');
        }
        $amount = (string) ($body['amount'] ?? '');
        if (! preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/', $amount)) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'amount must be a decimal string with two digits');
        }
        $description = (string) ($body['description'] ?? '');
        if ('' === $description || strlen($description) > 15) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'description must be 1-15 characters');
        }
        if ('' === (string) ($body['user']['legal_name'] ?? '')) {
            return self::error(400, 'INVALID_REQUEST', 'MISSING_FIELDS', 'user.legal_name is required');
        }
        foreach ((array) ($body['metadata'] ?? array()) as $key => $value) {
            if (! is_string($value) || strlen((string) $key) > 40 || ! preg_match('/^[\x20-\x7E]*$/', $value)) {
                return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'metadata values must be ASCII strings');
            }
        }
        $state = self::state();
        $id = wp_generate_uuid4();
        $state['intents'][$id] = array(
            'id' => $id, 'status' => 'PENDING', 'amount' => $amount, 'iso_currency_code' => (string) ($body['iso_currency_code'] ?? 'USD'),
            'mode' => 'PAYMENT', 'description' => $description, 'ach_class' => (string) ($body['ach_class'] ?? ''), 'network' => (string) ($body['network'] ?? 'same-day-ach'),
            'metadata' => $body['metadata'] ?? null, 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'transfer_id' => null, 'failure_reason' => null,
            'authorization_decision' => null, 'authorization_decision_rationale' => null, 'funding_account_id' => '', 'environment' => $environment,
            'user' => array('legal_name' => $body['user']['legal_name'], 'email_address' => $body['user']['email_address'] ?? null, 'phone_number' => null, 'address' => null),
        );
        if (str_starts_with($failure, 'amount:')) {
            $state['intents'][$id]['amount'] = substr($failure, 7);
        }
        self::save($state);
        return self::ok(array('transfer_intent' => $state['intents'][$id]));
    }

    /** @param array<string, mixed> $body */
    private static function intent_get(array $body): array
    {
        $intent = self::state()['intents'][(string) ($body['transfer_intent_id'] ?? '')] ?? null;
        return null === $intent ? self::error(400, 'INVALID_INPUT', 'INVALID_FIELD', 'transfer intent not found') : self::ok(array('transfer_intent' => $intent));
    }

    /** @param array<string, mixed> $body */
    private static function link_token_create(array $body): array
    {
        if (array('transfer') !== ($body['products'] ?? null) || array('US') !== ($body['country_codes'] ?? null) || '' === (string) ($body['user']['client_user_id'] ?? '')) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'invalid link token request');
        }
        $intent_id = (string) ($body['transfer']['intent_id'] ?? '');
        $state = self::state();
        if (! isset($state['intents'][$intent_id]) || 'PENDING' !== $state['intents'][$intent_id]['status']) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'transfer intent is not pending');
        }
        $token = 'link-sandbox-' . wp_generate_uuid4();
        $state['tokens'][$token] = $intent_id;
        self::save($state);
        return self::ok(array('link_token' => $token, 'expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + 4 * HOUR_IN_SECONDS)));
    }

    /** Simulates the customer completing Transfer UI with a Link token. */
    public static function authorize(string $link_token, string $outcome = 'success', ?string $transfer_amount = null): string
    {
        $state = self::state();
        $intent_id = $state['tokens'][$link_token] ?? '';
        if ('' === $intent_id || 'PENDING' !== ($state['intents'][$intent_id]['status'] ?? '')) {
            throw new RuntimeException('Link token cannot authorize a transfer.');
        }
        if ('nsf' === $outcome) {
            $state['intents'][$intent_id]['authorization_decision'] = 'DECLINED';
            $state['intents'][$intent_id]['authorization_decision_rationale'] = array('code' => 'NSF', 'description' => 'Insufficient funds');
            self::save($state);
            return '';
        }
        if ('failed' === $outcome) {
            $state['intents'][$intent_id]['status'] = 'FAILED';
            $state['intents'][$intent_id]['failure_reason'] = array('error_type' => 'TRANSFER_ERROR', 'error_code' => 'TRANSFER_UI_DECLINED', 'error_message' => 'declined');
            self::save($state);
            return '';
        }
        $transfer_id = wp_generate_uuid4();
        $intent = $state['intents'][$intent_id];
        $state['intents'][$intent_id]['status'] = 'SUCCEEDED';
        $state['intents'][$intent_id]['authorization_decision'] = 'APPROVED';
        $state['intents'][$intent_id]['transfer_id'] = $transfer_id;
        $state['transfers'][$transfer_id] = array(
            'id' => $transfer_id, 'type' => 'debit', 'amount' => $transfer_amount ?? $intent['amount'], 'iso_currency_code' => 'USD',
            'status' => 'pending', 'failure_reason' => null, 'metadata' => $intent['metadata'], 'network' => $intent['network'], 'ach_class' => $intent['ach_class'],
        );
        self::save($state);
        self::add_event($transfer_id, 'pending');
        return $transfer_id;
    }

    /** Appends the remaining Plaid Sandbox automatic-simulation events for the transfer amount. */
    public static function advance(string $transfer_id): void
    {
        $amount = (string) (self::state()['transfers'][$transfer_id]['amount'] ?? '');
        $plan = array(
            '11.11' => array('posted', 'settled', 'funds_available'),
            '22.22' => array('failed'),
            '33.33' => array('posted', 'settled', 'funds_available', 'returned'),
        )[$amount] ?? array('posted', 'settled', 'funds_available');
        foreach ($plan as $type) {
            self::add_event($transfer_id, $type, 'returned' === $type ? 'R01' : '');
        }
    }

    public static function add_event(string $transfer_id, string $type, string $code = ''): void
    {
        $state = self::state();
        $transfer = $state['transfers'][$transfer_id];
        $failure = null;
        if ('returned' === $type) {
            $failure = array('failure_code' => '' === $code ? 'R01' : $code, 'ach_return_code' => '' === $code ? 'R01' : $code, 'description' => 'Insufficient funds');
        } elseif ('failed' === $type) {
            $failure = array('failure_code' => null, 'ach_return_code' => null, 'description' => 'The transfer failed');
        }
        $event_id = (int) $state['next_event_id'];
        $state['events'][] = array(
            'event_id' => $event_id, 'event_type' => $type, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transfer_id' => $transfer_id, 'transfer_type' => 'debit',
            'transfer_amount' => $transfer['amount'], 'intent_id' => null, 'failure_reason' => $failure, 'account_id' => 'acc', 'funding_account_id' => '', 'ledger_id' => 'ledger-test',
            'originator_client_id' => null, 'refund_id' => null, 'sweep_amount' => null, 'sweep_id' => null,
        );
        $state['next_event_id'] = $event_id + 1;
        if (isset(PayBridge_Test_Plaid_Mock_Status::RANK[$type])) {
            $current = $state['transfers'][$transfer_id]['status'];
            if (PayBridge_Test_Plaid_Mock_Status::RANK[$type] >= (PayBridge_Test_Plaid_Mock_Status::RANK[$current] ?? 0)) {
                $state['transfers'][$transfer_id]['status'] = $type;
                $state['transfers'][$transfer_id]['failure_reason'] = $failure;
            }
        }
        self::save($state);
    }

    /** @param array<string, mixed> $body */
    private static function transfer_get(array $body): array
    {
        $transfer = self::state()['transfers'][(string) ($body['transfer_id'] ?? '')] ?? null;
        return null === $transfer ? self::error(400, 'INVALID_INPUT', 'INVALID_TRANSFER_ID', 'transfer not found') : self::ok(array('transfer' => $transfer));
    }

    /** @param array<string, mixed> $body */
    private static function event_sync(array $body): array
    {
        if (! is_int($body['after_id'] ?? null) || $body['after_id'] < 0) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'after_id must be a non-negative integer');
        }
        $count = max(1, min(500, (int) ($body['count'] ?? 100)));
        $events = array_values(array_filter(self::state()['events'], static fn (array $event): bool => $event['event_id'] > $body['after_id']));
        usort($events, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
        return self::ok(array('transfer_events' => array_slice($events, 0, $count), 'has_more' => count($events) > $count));
    }

    /** @param array<string, mixed> $body */
    private static function verification_key(array $body): array
    {
        if (self::KID !== ($body['key_id'] ?? null)) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_WEBHOOK_VERIFICATION_KEY_ID', 'key not found');
        }
        return self::ok(array('key' => self::key()['jwk']));
    }

    /** @return array{pem:string, jwk:array<string, mixed>} */
    public static function key(): array
    {
        $stored = get_option(self::KEY);
        if (is_array($stored) && isset($stored['pem'], $stored['jwk'])) {
            return $stored;
        }
        $key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $stored = array('pem' => $pem, 'jwk' => array('alg' => 'ES256', 'crv' => 'P-256', 'kid' => self::KID, 'kty' => 'EC', 'use' => 'sig', 'x' => $b64($details['ec']['x']), 'y' => $b64($details['ec']['y']), 'created_at' => time(), 'expired_at' => null));
        update_option(self::KEY, $stored, false);
        return $stored;
    }

    /** Signs a webhook body exactly like Plaid's Plaid-Verification header. */
    public static function sign(string $body, array $claims = array(), string $kid = self::KID): string
    {
        return \PayBridge\Plaid\Vendor\Firebase\JWT\JWT::encode($claims + array('iat' => time(), 'request_body_sha256' => hash('sha256', $body)), self::key()['pem'], 'ES256', $kid);
    }

    /** @param array<string, mixed> $data */
    private static function ok(array $data): array
    {
        return self::response(200, $data + array('request_id' => substr(md5((string) microtime(true)), 0, 15)));
    }

    private static function error(int $status, string $type, string $code, string $message): array
    {
        return self::response($status, array('error_type' => $type, 'error_code' => $code, 'error_message' => $message, 'display_message' => null, 'request_id' => substr(md5((string) microtime(true)), 0, 15)));
    }

    /** @param array<string, mixed> $data */
    private static function response(int $status, array $data): array
    {
        return array('headers' => array(), 'body' => (string) wp_json_encode($data), 'response' => array('code' => $status, 'message' => ''), 'cookies' => array(), 'filename' => null);
    }
}

final class PayBridge_Test_Plaid_Mock_Status
{
    public const RANK = array('pending' => 1, 'posted' => 2, 'settled' => 3, 'funds_available' => 4, 'failed' => 9, 'cancelled' => 9, 'returned' => 10);
}

add_filter('pre_http_request', array(PayBridge_Test_Plaid_Mock::class, 'handle'), 10, 3);

// A disposable site must never reach the real network.
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (false !== $preempt) {
        return $preempt;
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (in_array($host, array('127.0.0.1', 'localhost'), true) || str_ends_with($host, '.test')) {
        return $preempt;
    }
    return new WP_Error('pbfp_test_network_blocked', 'External HTTP is blocked on the disposable test site: ' . $host);
}, 99, 3);
