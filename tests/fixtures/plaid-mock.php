<?php
/**
 * Deterministic Plaid API double for disposable integration/browser sites (MU plugin).
 *
 * It intercepts only sandbox.plaid.com / production.plaid.com requests through
 * WordPress' pre_http_request filter, validates request bodies against the
 * documented contracts and mirrors Plaid Sandbox behavior, including
 * the Plaid Ledger rejection of funding_account_id and the $11.11 / $22.22 /
 * $33.33 transfer scenarios. Like real Plaid clients, every client_id has its own
 * intents, transfers, refunds and transfer-event stream (event IDs start at 1 per
 * client), so account switches can be tested. State lives in a WordPress option so
 * separate PHP processes share it. It is inert unless BUCKMERCE_PLAID_TEST_DATABASE is true.
 */

declare(strict_types=1);

if (! defined('BUCKMERCE_PLAID_TEST_DATABASE') || true !== BUCKMERCE_PLAID_TEST_DATABASE) {
    return;
}

// Never use the host mailer on a disposable site; record mails for assertions.
add_filter('pre_wp_mail', static function ($short_circuit, array $atts) {
    $mails = get_option('bmfp_test_mails', array());
    $mails = is_array($mails) ? $mails : array();
    $mails[] = array('to' => $atts['to'] ?? '', 'subject' => $atts['subject'] ?? '');
    update_option('bmfp_test_mails', $mails, false);
    return true;
}, 10, 2);

final class Buckmerce_Test_Plaid_Mock
{
    public const STATE = 'bmfp_test_plaid_state';
    public const KEY = 'bmfp_test_plaid_key';
    public const KID = 'bmfp-test-kid-1';
    /** Client of the integration suites (tests/Integration/helpers.php bmfp_configure()). */
    public const DEFAULT_CLIENT = 'test-client-id';
    /** Client of the request being handled (set by handle()). */
    private static string $client = self::DEFAULT_CLIENT;

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
        return array('intents' => array(), 'transfers' => array(), 'tokens' => array(), 'events' => array(), 'refunds' => array(), 'refund_keys' => array(), 'next_event_ids' => array(), 'calls' => array(), 'fail' => array());
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
        delete_option('bmfp_test_mails');
        wp_cache_delete(self::STATE, 'options');
    }

    /** Queue a failure for the next call to $path: timeout|timeout_after_create|server_error|server_error_after_create|rate_limit|reject|delay:<seconds>|key_fail|amount:<value>. */
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

        self::$client = (string) $headers['PLAID-CLIENT-ID'];
        $state = self::state();
        $state['calls'][] = array('path' => $path, 'body' => $body, 'environment' => $environment, 'client_id' => self::$client);
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
        if ('server_error_after_create' === $failure && '/transfer/refund/create' === $path) {
            self::refund_create($body);
            return self::error(502, 'API_ERROR', 'INTERNAL_SERVER_ERROR', 'bad gateway after the refund was created');
        }
        if ('rate_limit' === $failure) {
            return self::error(429, 'RATE_LIMIT_EXCEEDED', 'RATE_LIMIT', 'rate limit exceeded for this endpoint');
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
            case '/transfer/cancel':
                return self::transfer_cancel($body);
            case '/transfer/refund/create':
                $response = self::refund_create($body);
                return 'timeout_after_create' === $failure ? new WP_Error('http_request_failed', 'cURL error 28: response lost after refund create') : $response;
            case '/transfer/refund/get':
                $refund = self::owned(self::state()['refunds'][(string) ($body['refund_id'] ?? '')] ?? null);
                return null === $refund ? self::error(400, 'INVALID_INPUT', 'INVALID_FIELD', 'refund not found') : self::ok(array('refund' => self::public_record($refund)));
            case '/transfer/refund/cancel':
                return self::refund_cancel($body);
            case '/sandbox/transfer/refund/simulate':
                return self::refund_simulate($body);
            case '/transfer/event/sync':
                return self::event_sync($body);
            case '/transfer/configuration/get':
                return self::ok(array('iso_currency_code' => 'USD', 'max_single_transfer_amount' => ''));
            case '/transfer/ledger/get':
                return self::ok(array('balance' => array('available' => '100.00', 'pending' => '0.00'), 'is_default' => true, 'ledger_id' => 'ledger-test', 'name' => 'default'));
            case '/webhook_verification_key/get':
                return self::verification_key($body);
            case '/sandbox/transfer/fire_webhook':
                // Plaid would now send a signed webhook to the URL; the double only accepts the request.
                return is_string($body['webhook'] ?? null) && str_starts_with($body['webhook'], 'https://')
                    ? self::ok(array())
                    : self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'webhook must be an HTTPS URL');
            case '/sandbox/transfer/simulate':
                $transfer_id = (string) ($body['transfer_id'] ?? '');
                if (null === self::owned(self::state()['transfers'][$transfer_id] ?? null)) {
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
            'client_id' => self::$client,
        );
        if (str_starts_with($failure, 'amount:')) {
            $state['intents'][$id]['amount'] = substr($failure, 7);
        }
        self::save($state);
        return self::ok(array('transfer_intent' => self::public_record($state['intents'][$id])));
    }

    /** @param array<string, mixed> $body */
    private static function intent_get(array $body): array
    {
        $intent = self::owned(self::state()['intents'][(string) ($body['transfer_intent_id'] ?? '')] ?? null);
        return null === $intent ? self::error(400, 'INVALID_INPUT', 'INVALID_FIELD', 'transfer intent not found') : self::ok(array('transfer_intent' => self::public_record($intent)));
    }

    /** @param array<string, mixed> $body */
    private static function link_token_create(array $body): array
    {
        if (array('transfer') !== ($body['products'] ?? null) || array('US') !== ($body['country_codes'] ?? null) || '' === (string) ($body['user']['client_user_id'] ?? '')) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'invalid link token request');
        }
        if ('' === (string) ($body['link_customization_name'] ?? '')) {
            // Plaid itself would fall back to its default customization; Buckmerce must never rely on that.
            return self::error(400, 'INVALID_REQUEST', 'MISSING_FIELDS', 'test double: Buckmerce must always send link_customization_name');
        }
        if ('invalid_customization' === ($body['link_customization_name'] ?? '')) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_LINK_CUSTOMIZATION', 'the link customization is not valid for the request');
        }
        $intent_id = (string) ($body['transfer']['intent_id'] ?? '');
        $state = self::state();
        if (null === self::owned($state['intents'][$intent_id] ?? null) || 'PENDING' !== $state['intents'][$intent_id]['status']) {
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
            'created' => gmdate('Y-m-d\TH:i:s\Z'), 'client_id' => $intent['client_id'] ?? self::DEFAULT_CLIENT,
        );
        self::save($state);
        self::add_event($transfer_id, 'pending');
        return $transfer_id;
    }

    /**
     * A transfer created outside this store (another store or integration on the same Plaid account).
     *
     * @param array<string, string>|null $metadata
     */
    public static function foreign_transfer(?array $metadata, string $amount = '5.00', ?int $timestamp = null, string $client_id = self::DEFAULT_CLIENT): string
    {
        $state = self::state();
        $transfer_id = wp_generate_uuid4();
        $state['transfers'][$transfer_id] = array('id' => $transfer_id, 'type' => 'debit', 'amount' => $amount, 'iso_currency_code' => 'USD', 'status' => 'pending', 'failure_reason' => null, 'metadata' => $metadata, 'network' => 'ach', 'ach_class' => 'web', 'client_id' => $client_id);
        self::save($state);
        self::add_event($transfer_id, 'pending', '', $timestamp);
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

    public static function add_event(string $transfer_id, string $type, string $code = '', ?int $timestamp = null): void
    {
        $state = self::state();
        $transfer = $state['transfers'][$transfer_id];
        $failure = null;
        if ('returned' === $type) {
            $failure = array('failure_code' => '' === $code ? 'R01' : $code, 'ach_return_code' => '' === $code ? 'R01' : $code, 'description' => 'Insufficient funds');
        } elseif ('failed' === $type) {
            $failure = array('failure_code' => null, 'ach_return_code' => null, 'description' => 'The transfer failed');
        }
        $client = (string) ($transfer['client_id'] ?? self::DEFAULT_CLIENT);
        $event_id = self::next_event_id($state, $client);
        $state['events'][] = array(
            'event_id' => $event_id, 'event_type' => $type, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $timestamp ?? time()), 'transfer_id' => $transfer_id, 'transfer_type' => 'debit',
            'transfer_amount' => $transfer['amount'], 'intent_id' => null, 'failure_reason' => $failure, 'account_id' => 'acc', 'funding_account_id' => '', 'ledger_id' => 'ledger-test',
            'originator_client_id' => null, 'refund_id' => null, 'sweep_amount' => null, 'sweep_id' => null, 'event_amount' => $transfer['amount'], 'client_id' => $client,
        );
        if (isset(Buckmerce_Test_Plaid_Mock_Status::RANK[$type])) {
            $current = $state['transfers'][$transfer_id]['status'];
            if (Buckmerce_Test_Plaid_Mock_Status::RANK[$type] >= (Buckmerce_Test_Plaid_Mock_Status::RANK[$current] ?? 0)) {
                $state['transfers'][$transfer_id]['status'] = $type;
                $state['transfers'][$transfer_id]['failure_reason'] = $failure;
            }
        }
        if ('settled' === $type && empty($state['transfers'][$transfer_id]['standard_return_window'])) {
            // Plaid: standard window = settlement + 3 business days, unauthorized = + 61 business days.
            $settled = $timestamp ?? time();
            $state['transfers'][$transfer_id]['standard_return_window'] = gmdate('Y-m-d', $settled + 5 * DAY_IN_SECONDS);
            $state['transfers'][$transfer_id]['unauthorized_return_window'] = gmdate('Y-m-d', $settled + 87 * DAY_IN_SECONDS);
            $state['transfers'][$transfer_id]['expected_funds_available_date'] = gmdate('Y-m-d', $settled + 2 * DAY_IN_SECONDS);
        }
        self::save($state);
    }

    /** @param array<string, mixed> $body */
    private static function transfer_get(array $body): array
    {
        $state = self::state();
        $transfer = self::owned($state['transfers'][(string) ($body['transfer_id'] ?? '')] ?? null);
        if (null === $transfer) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_TRANSFER_ID', 'transfer not found');
        }
        $transfer['cancellable'] = 'pending' === $transfer['status'];
        $transfer['refunds'] = array_map(array(self::class, 'public_record'), array_values(array_filter($state['refunds'], static fn (array $refund): bool => $refund['transfer_id'] === $transfer['id'])));
        $transfer += array('created' => gmdate('Y-m-d\TH:i:s\Z'), 'standard_return_window' => null, 'unauthorized_return_window' => null, 'expected_funds_available_date' => null);
        return self::ok(array('transfer' => self::public_record($transfer)));
    }

    /** @param array<string, mixed> $body */
    private static function transfer_cancel(array $body): array
    {
        $transfer_id = (string) ($body['transfer_id'] ?? '');
        $transfer = self::owned(self::state()['transfers'][$transfer_id] ?? null);
        if (null === $transfer) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_TRANSFER_ID', 'transfer not found');
        }
        if ('pending' !== $transfer['status']) {
            return self::error(400, 'TRANSFER_ERROR', 'TRANSFER_NOT_CANCELLABLE', 'transfer is not cancellable');
        }
        self::add_event($transfer_id, 'cancelled');
        return self::ok(array());
    }

    /**
     * /transfer/refund/create per https://plaid.com/docs/api/products/transfer/refunds/: idempotency_key
     * (≤ 50) dedupes, at most 10 refunds, total ≤ transfer amount, no refunds of cancelled,
     * failed or returned transfers; Sandbox $1.11 → returned and $2.22 → failed immediately.
     *
     * @param array<string, mixed> $body
     */
    private static function refund_create(array $body): array
    {
        $key = (string) ($body['idempotency_key'] ?? '');
        $amount = (string) ($body['amount'] ?? '');
        $transfer_id = (string) ($body['transfer_id'] ?? '');
        if ('' === $key || strlen($key) > 50) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'idempotency_key must be 1-50 characters');
        }
        if (! preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/', $amount) || '0.00' === $amount) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'amount must be a positive decimal string with two digits');
        }
        $state = self::state();
        $key = self::$client . '|' . $key; // Plaid idempotency keys are per client.
        if (isset($state['refund_keys'][$key])) {
            $existing = $state['refunds'][$state['refund_keys'][$key]];
            if ($existing['amount'] !== $amount || $existing['transfer_id'] !== $transfer_id) {
                return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'idempotency key reused with different parameters');
            }
            return self::ok(array('refund' => self::public_record($existing)));
        }
        $transfer = self::owned($state['transfers'][$transfer_id] ?? null);
        if (null === $transfer) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_TRANSFER_ID', 'transfer not found');
        }
        if (in_array($transfer['status'], array('cancelled', 'failed', 'returned'), true)) {
            return self::error(400, 'TRANSFER_ERROR', 'TRANSFER_NOT_REFUNDABLE', 'transfers in a cancelled, failed or returned state cannot be refunded');
        }
        $existing = array_filter($state['refunds'], static fn (array $refund): bool => $refund['transfer_id'] === $transfer_id);
        if (count($existing) >= 10) {
            return self::error(400, 'TRANSFER_ERROR', 'TRANSFER_REFUND_LIMIT_REACHED', 'a transfer can have at most 10 refunds');
        }
        $cents = static fn (string $value): int => (int) round(((float) $value) * 100);
        $total = $cents($amount);
        foreach ($existing as $refund) {
            if (! in_array($refund['status'], array('failed', 'cancelled'), true)) {
                $total += $cents($refund['amount']);
            }
        }
        if ($total > $cents($transfer['amount'])) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'refund amount exceeds the refundable amount of the transfer');
        }
        $id = wp_generate_uuid4();
        $state['refunds'][$id] = array('id' => $id, 'transfer_id' => $transfer_id, 'amount' => $amount, 'status' => 'pending', 'failure_reason' => null, 'ledger_id' => 'ledger-test', 'created' => gmdate('Y-m-d\TH:i:s\Z'), 'network_trace_id' => null, 'client_id' => self::$client);
        $state['refund_keys'][$key] = $id;
        self::save($state);
        self::add_refund_event($id, 'refund.pending');
        $plan = array('1.11' => array('refund.posted', 'refund.settled', 'refund.returned'), '2.22' => array('refund.failed'))[$amount] ?? array();
        foreach ($plan as $type) {
            self::add_refund_event($id, $type, 'refund.returned' === $type ? 'R01' : '');
        }
        return self::ok(array('refund' => self::public_record(self::state()['refunds'][$id])));
    }

    /** @param array<string, mixed> $body */
    private static function refund_cancel(array $body): array
    {
        $refund_id = (string) ($body['refund_id'] ?? '');
        $refund = self::owned(self::state()['refunds'][$refund_id] ?? null);
        if (null === $refund) {
            return self::error(400, 'INVALID_INPUT', 'INVALID_FIELD', 'refund not found');
        }
        if ('pending' !== $refund['status']) {
            return self::error(400, 'TRANSFER_ERROR', 'TRANSFER_REFUND_NOT_CANCELLABLE', 'refund was already submitted to the network');
        }
        self::add_refund_event($refund_id, 'refund.cancelled');
        return self::ok(array());
    }

    /** @param array<string, mixed> $body */
    private static function refund_simulate(array $body): array
    {
        $refund_id = (string) ($body['refund_id'] ?? '');
        $type = (string) ($body['event_type'] ?? '');
        $refund = self::owned(self::state()['refunds'][$refund_id] ?? null);
        $allowed = array('pending' => array('refund.failed', 'refund.posted'), 'posted' => array('refund.returned', 'refund.settled'));
        if (null === $refund || ! in_array($type, $allowed[$refund['status']] ?? array(), true)) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'event type is incompatible with the refund status');
        }
        self::add_refund_event($refund_id, $type, (string) ($body['failure_reason']['failure_code'] ?? ''));
        return self::ok(array());
    }

    public static function add_refund_event(string $refund_id, string $type, string $code = ''): void
    {
        $state = self::state();
        $refund = $state['refunds'][$refund_id];
        $transfer = $state['transfers'][$refund['transfer_id']];
        $status = substr($type, strlen('refund.'));
        $failure = null;
        if ('returned' === $status) {
            $failure = array('failure_code' => '' === $code ? 'R01' : $code, 'ach_return_code' => '' === $code ? 'R01' : $code, 'description' => 'Refund returned');
        } elseif ('failed' === $status) {
            $failure = array('failure_code' => null, 'ach_return_code' => null, 'description' => 'The refund failed');
        }
        $client = (string) ($refund['client_id'] ?? self::DEFAULT_CLIENT);
        $event_id = self::next_event_id($state, $client);
        $state['events'][] = array(
            'event_id' => $event_id, 'event_type' => $type, 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transfer_id' => $refund['transfer_id'], 'transfer_type' => $transfer['type'],
            'transfer_amount' => $refund['amount'], 'intent_id' => null, 'failure_reason' => $failure, 'account_id' => 'acc', 'funding_account_id' => '', 'ledger_id' => 'ledger-test',
            'originator_client_id' => null, 'refund_id' => $refund_id, 'sweep_amount' => null, 'sweep_id' => null, 'event_amount' => $refund['amount'], 'client_id' => $client,
        );
        $state['refunds'][$refund_id]['status'] = $status;
        $state['refunds'][$refund_id]['failure_reason'] = $failure;
        self::save($state);
    }

    /** @param array<string, mixed> $body */
    private static function event_sync(array $body): array
    {
        if (! is_int($body['after_id'] ?? null) || $body['after_id'] < 0) {
            return self::error(400, 'INVALID_REQUEST', 'INVALID_FIELD', 'after_id must be a non-negative integer');
        }
        $count = max(1, min(500, (int) ($body['count'] ?? 100)));
        $client = self::$client;
        $events = array_values(array_filter(self::state()['events'], static fn (array $event): bool => ($event['client_id'] ?? self::DEFAULT_CLIENT) === $client && $event['event_id'] > $body['after_id']));
        usort($events, static fn (array $a, array $b): int => $a['event_id'] <=> $b['event_id']);
        return self::ok(array('transfer_events' => array_map(array(self::class, 'public_record'), array_slice($events, 0, $count)), 'has_more' => count($events) > $count));
    }

    /** Next event ID of one client's stream: every Plaid client's stream starts at 1. */
    private static function next_event_id(array &$state, string $client): int
    {
        $next = (int) ($state['next_event_ids'][$client] ?? 1);
        $state['next_event_ids'][$client] = $next + 1;
        return $next;
    }

    /**
     * Test helper: an event with an explicit ID in one client's stream (account-switch and
     * collision tests: two accounts can both have event 5, refund X, transfer Y).
     *
     * @param array<string, mixed> $fields
     */
    public static function add_raw_event(string $client_id, array $fields): void
    {
        $state = self::state();
        $event_id = (int) ($fields['event_id'] ?? self::next_event_id($state, $client_id));
        $state['next_event_ids'][$client_id] = max((int) ($state['next_event_ids'][$client_id] ?? 1), $event_id + 1);
        $state['events'][] = $fields + array(
            'event_id' => $event_id, 'event_type' => 'pending', 'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'transfer_id' => wp_generate_uuid4(), 'transfer_type' => 'debit',
            'transfer_amount' => '5.00', 'intent_id' => null, 'failure_reason' => null, 'account_id' => 'acc', 'funding_account_id' => '', 'ledger_id' => 'ledger-test',
            'originator_client_id' => null, 'refund_id' => null, 'sweep_amount' => null, 'sweep_id' => null, 'event_amount' => '5.00', 'client_id' => $client_id,
        );
        $state['events'][count($state['events']) - 1]['client_id'] = $client_id;
        self::save($state);
    }

    /**
     * Test helper: appends many historical events of other integrations to one client's stream
     * in a single state write (IDs continue the client's stream; transfers are unknown here).
     */
    public static function add_history(string $client_id, int $count, int $timestamp): void
    {
        $state = self::state();
        for ($i = 0; $i < $count; ++$i) {
            $event_id = self::next_event_id($state, $client_id);
            $state['events'][] = array(
                'event_id' => $event_id, 'event_type' => 0 === $i % 3 ? 'pending' : (1 === $i % 3 ? 'posted' : 'settled'), 'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                'transfer_id' => 'hist-' . $client_id . '-' . intdiv($i, 3), 'transfer_type' => 'debit', 'transfer_amount' => '9.99', 'intent_id' => null, 'failure_reason' => null,
                'account_id' => 'acc', 'funding_account_id' => '', 'ledger_id' => 'ledger-test', 'originator_client_id' => null, 'refund_id' => null, 'sweep_amount' => null,
                'sweep_id' => null, 'event_amount' => '9.99', 'client_id' => $client_id,
            );
        }
        self::save($state);
    }

    /** Highest event ID of one client's stream (0 when empty). */
    public static function last_event_id(string $client_id): int
    {
        return (int) (self::state()['next_event_ids'][$client_id] ?? 1) - 1;
    }

    /** Test helper: a transfer owned by a client (to test identical IDs across accounts). */
    public static function put_transfer(string $client_id, array $transfer): void
    {
        $state = self::state();
        $state['transfers'][(string) $transfer['id']] = $transfer + array('type' => 'debit', 'iso_currency_code' => 'USD', 'status' => 'pending', 'failure_reason' => null, 'metadata' => null, 'network' => 'ach', 'ach_class' => 'web', 'client_id' => $client_id);
        self::save($state);
    }

    /** @param array<string, mixed>|null $record Only records of the requesting client are visible (like Plaid). */
    private static function owned(?array $record): ?array
    {
        return null !== $record && ($record['client_id'] ?? self::DEFAULT_CLIENT) === self::$client ? $record : null;
    }

    /** @param array<string, mixed> $record Plaid objects never carry the test double's client bookkeeping. */
    public static function public_record(array $record): array
    {
        unset($record['client_id']);
        return $record;
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
        return \Buckmerce\Plaid\Vendor\Firebase\JWT\JWT::encode($claims + array('iat' => time(), 'request_body_sha256' => hash('sha256', $body)), self::key()['pem'], 'ES256', $kid);
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

final class Buckmerce_Test_Plaid_Mock_Status
{
    public const RANK = array('pending' => 1, 'posted' => 2, 'settled' => 3, 'funds_available' => 4, 'failed' => 9, 'cancelled' => 9, 'returned' => 10);
}

add_filter('pre_http_request', array(Buckmerce_Test_Plaid_Mock::class, 'handle'), 10, 3);

// A disposable site must never reach the real network.
add_filter('pre_http_request', static function ($preempt, array $args, string $url) {
    if (false !== $preempt) {
        return $preempt;
    }
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if (in_array($host, array('127.0.0.1', 'localhost'), true) || str_ends_with($host, '.test')) {
        return $preempt;
    }
    return new WP_Error('bmfp_test_network_blocked', 'External HTTP is blocked on the disposable test site: ' . $host);
}, 99, 3);
