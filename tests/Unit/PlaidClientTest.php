<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Tests\Unit;

use PayBridge\Plaid\Exception\ConfigurationException;
use PayBridge\Plaid\Plaid\Client\PlaidClient;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;
use PayBridge\Plaid\Plaid\Exception\PlaidNetworkException;
use PayBridge\Plaid\Plaid\PlaidEnvironment;
use PHPUnit\Framework\TestCase;

final class PlaidClientTest extends TestCase
{
    /** @var list<array{url:string, args:array<string, mixed>}> */
    private array $requests = array();

    private function client(string $environment, int $status, string $body, string $secret = 'test-secret-value'): PlaidClient
    {
        return new PlaidClient(PlaidEnvironment::from_string($environment), 'client-123', $secret, function (string $url, array $args) use ($status, $body): array {
            $this->requests[] = array('url' => $url, 'args' => $args);
            return array('status' => $status, 'body' => $body);
        });
    }

    public function test_request_uses_fixed_host_header_auth_json_and_finite_timeout(): void
    {
        $response = $this->client('sandbox', 200, '{"request_id":"req-1","ok":true}')->post('/transfer/intent/get', array('transfer_intent_id' => 'ti-1'));
        self::assertSame('req-1', $response->request_id);
        $request = $this->requests[0];
        self::assertSame('https://sandbox.plaid.com/transfer/intent/get', $request['url']);
        self::assertSame('client-123', $request['args']['headers']['PLAID-CLIENT-ID']);
        self::assertSame('test-secret-value', $request['args']['headers']['PLAID-SECRET']);
        self::assertSame('2020-09-14', $request['args']['headers']['Plaid-Version']);
        self::assertSame('application/json', $request['args']['headers']['Content-Type']);
        self::assertSame(PlaidClient::TIMEOUT_SECONDS, $request['args']['timeout']);
        self::assertSame(0, $request['args']['redirection']);
        self::assertTrue($request['args']['sslverify']);
        self::assertSame('{"transfer_intent_id":"ti-1"}', $request['args']['body']);
        self::assertStringNotContainsString('secret', $request['args']['body']);
    }

    public function test_production_host_and_empty_body_is_a_json_object(): void
    {
        $this->client('production', 200, '{"request_id":"r"}')->post('/transfer/configuration/get', array());
        self::assertSame('https://production.plaid.com/transfer/configuration/get', $this->requests[0]['url']);
        self::assertSame('{}', $this->requests[0]['args']['body']);
    }

    public function test_structured_plaid_error_is_typed_and_classified(): void
    {
        $body = '{"error_type":"INVALID_REQUEST","error_code":"INVALID_FIELD","error_message":"funding_account_id cannot be set when ledger is enabled","display_message":null,"request_id":"25f441ecfc091b5"}';
        try {
            $this->client('sandbox', 400, $body)->post('/transfer/intent/create', array('mode' => 'PAYMENT'));
            self::fail('Expected API exception.');
        } catch (PlaidApiException $exception) {
            self::assertSame(400, $exception->http_status);
            self::assertSame('INVALID_FIELD', $exception->error_code);
            self::assertSame('25f441ecfc091b5', $exception->request_id());
            self::assertFalse($exception->is_ambiguous());
            self::assertSame('invalid_field', $exception->safe_code());
            self::assertStringNotContainsString('test-secret-value', $exception->getMessage());
        }
    }

    public function test_server_errors_are_ambiguous(): void
    {
        try {
            $this->client('sandbox', 500, '{"error_type":"API_ERROR","error_code":"INTERNAL_SERVER_ERROR","error_message":"x","request_id":"r5"}')->post('/transfer/intent/create', array());
            self::fail('Expected API exception.');
        } catch (PlaidApiException $exception) {
            self::assertTrue($exception->is_ambiguous());
        }
    }

    public function test_malformed_response_is_ambiguous(): void
    {
        $this->expectException(PlaidMalformedResponseException::class);
        $this->client('sandbox', 502, '<html>bad gateway</html>')->post('/transfer/get', array('transfer_id' => 't'));
    }

    public function test_network_error_propagates_as_ambiguous(): void
    {
        $client = new PlaidClient(PlaidEnvironment::from_string('sandbox'), 'c', 's', static function (): array {
            throw new PlaidNetworkException('Plaid could not be reached: http_request_failed');
        });
        try {
            $client->post('/transfer/intent/create', array());
            self::fail('Expected network exception.');
        } catch (PlaidNetworkException $exception) {
            self::assertTrue($exception->is_ambiguous());
        }
    }

    public function test_sandbox_endpoints_are_blocked_in_production(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client('production', 200, '{}')->post('/sandbox/transfer/simulate', array());
    }

    public function test_unknown_endpoints_are_rejected_before_any_http_call(): void
    {
        try {
            $this->client('sandbox', 200, '{}')->post('/item/remove', array());
            self::fail('Expected rejection.');
        } catch (ConfigurationException $exception) {
            self::assertSame(array(), $this->requests);
        }
    }

    public function test_missing_credentials_fail_closed(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->client('sandbox', 200, '{}', '')->post('/transfer/get', array());
    }

    public function test_large_integers_are_decoded_as_strings(): void
    {
        $response = $this->client('sandbox', 200, '{"request_id":"r","value":18446744073709551615}')->post('/transfer/event/sync', array());
        self::assertSame('18446744073709551615', $response->data['value']);
    }
}
