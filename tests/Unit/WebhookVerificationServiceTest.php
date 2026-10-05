<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Tests\Unit;

use Buckmerce\Plaid\Plaid\Client\PlaidResponse;
use Buckmerce\Plaid\Plaid\Exception\PlaidApiException;
use Buckmerce\Plaid\Plaid\Exception\PlaidNetworkException;
use Buckmerce\Plaid\Plaid\Exception\WebhookVerificationException;
use Buckmerce\Plaid\Plaid\Webhook\VerificationKeyProvider;
use Buckmerce\Plaid\Plaid\Webhook\WebhookVerificationService;
use Buckmerce\Plaid\Tests\Support\FakePlaidClient;
use Buckmerce\Plaid\Vendor\Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

/** Webhook security matrix against real ES256 signatures. */
final class WebhookVerificationServiceTest extends TestCase
{
    private const KID = 'bfbd5111-8e33-4643-8ced-b2e642a72f3c';
    private const BODY = '{"webhook_type":"TRANSFER","webhook_code":"TRANSFER_EVENTS_UPDATE","environment":"sandbox"}';

    private static string $private_pem = '';
    /** @var array<string, mixed> */
    private static array $jwk = array();
    private static string $other_private_pem = '';

    public static function setUpBeforeClass(): void
    {
        [self::$private_pem, self::$jwk] = self::key_pair(self::KID);
        [self::$other_private_pem] = self::key_pair('other');
    }

    protected function setUp(): void
    {
        \BuckmerceTestStore::reset();
    }

    /** @return array{string, array<string, mixed>} */
    private static function key_pair(string $kid): array
    {
        $key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        self::assertNotFalse($key);
        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return array((string) $pem, array(
            'alg' => 'ES256', 'crv' => 'P-256', 'kid' => $kid, 'kty' => 'EC', 'use' => 'sig',
            'x' => $b64($details['ec']['x']), 'y' => $b64($details['ec']['y']), 'created_at' => 1560466150, 'expired_at' => null,
        ));
    }

    /** @param array<string, mixed>|null $key */
    private function service(?array $key = null, ?\Closure $failure = null, ?int $now = null): array
    {
        $client = new FakePlaidClient();
        $client->on('/webhook_verification_key/get', function (array $body) use ($key, $failure): PlaidResponse {
            if (null !== $failure) {
                $failure();
            }
            return new PlaidResponse(array('key' => $key ?? self::$jwk, 'request_id' => 'r'), 'r');
        });
        $provider = new VerificationKeyProvider($client, static fn (string $k) => \BuckmerceTestStore::$transients[$k] ?? false, static function (string $k, $v, int $ttl): bool {
            \BuckmerceTestStore::$transients[$k] = $v;
            return true;
        });
        $clock = null === $now ? null : static fn (): int => $now;
        return array(new WebhookVerificationService($provider, $clock), $client);
    }

    /** @param array<string, mixed> $claims */
    private static function jwt(string $body, array $claims = array(), ?string $pem = null, string $kid = self::KID): string
    {
        return JWT::encode($claims + array('iat' => time(), 'request_body_sha256' => hash('sha256', $body)), $pem ?? self::$private_pem, 'ES256', $kid);
    }

    private function assertRejected(string $reason, WebhookVerificationService $service, string $body, string $jwt): void
    {
        try {
            $service->verify($body, $jwt);
            self::fail('Expected webhook rejection: ' . $reason);
        } catch (WebhookVerificationException $exception) {
            self::assertSame($reason, $exception->reason);
        }
    }

    public function test_valid_signed_webhook_is_accepted_and_key_is_cached(): void
    {
        [$service, $client] = $this->service();
        $body = $service->verify(self::BODY, self::jwt(self::BODY));
        self::assertSame('TRANSFER_EVENTS_UPDATE', $body['webhook_code']);
        $service->verify(self::BODY, self::jwt(self::BODY));
        self::assertSame(array('/webhook_verification_key/get'), $client->paths(), 'The verification key is fetched once and cached.');
        self::assertSame(array('key_id' => self::KID), $client->calls[0]['body']);
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        [$service] = $this->service();
        $this->assertRejected('invalid_signature', $service, self::BODY, self::jwt(self::BODY, array(), self::$other_private_pem));
    }

    public function test_modified_body_is_rejected(): void
    {
        [$service] = $this->service();
        $tampered = str_replace('sandbox', 'production', self::BODY);
        $this->assertRejected('body_hash_mismatch', $service, $tampered, self::jwt(self::BODY));
    }

    public function test_wrong_algorithms_are_rejected_before_key_lookup(): void
    {
        [$service, $client] = $this->service();
        $hs256 = JWT::encode(array('iat' => time(), 'request_body_sha256' => hash('sha256', self::BODY)), str_repeat('attacker-guess-', 4), 'HS256', self::KID);
        $this->assertRejected('wrong_alg', $service, self::BODY, $hs256);
        $none = rtrim(strtr(base64_encode('{"alg":"none","kid":"' . self::KID . '"}'), '+/', '-_'), '=') . '.' . rtrim(strtr(base64_encode('{"iat":' . time() . '}'), '+/', '-_'), '=') . '.';
        $this->assertRejected('wrong_alg', $service, self::BODY, $none);
        self::assertSame(array(), $client->calls, 'No key lookup for a disallowed algorithm.');
    }

    public function test_unknown_kid_is_rejected_and_negatively_cached(): void
    {
        [$service, $client] = $this->service(null, static function (): void {
            throw new PlaidApiException(400, 'INVALID_INPUT', 'INVALID_WEBHOOK_VERIFICATION_KEY_ID', 'unknown key', '', 'r');
        });
        $jwt = self::jwt(self::BODY, array(), null, 'unknown-kid');
        $this->assertRejected('unknown_key', $service, self::BODY, $jwt);
        $this->assertRejected('unknown_key', $service, self::BODY, $jwt);
        self::assertCount(1, $client->calls, 'Unknown key IDs are negatively cached.');
    }

    public function test_rate_limited_key_lookup_is_retryable_and_not_negatively_cached(): void
    {
        $limited = true;
        [$service, $client] = $this->service(null, static function () use (&$limited): void {
            if ($limited) {
                throw new PlaidApiException(429, 'RATE_LIMIT_EXCEEDED', 'RATE_LIMIT', 'rate limit exceeded', '', 'r');
            }
        });
        $jwt = self::jwt(self::BODY);
        $this->assertRejected('key_unavailable', $service, self::BODY, $jwt);
        $limited = false;
        self::assertSame('TRANSFER', $service->verify(self::BODY, $jwt)['webhook_type'], 'A genuine key is fetched again once Plaid stops rate limiting.');
        self::assertCount(2, $client->calls);
    }

    public function test_key_retrieval_failure_is_rejected_not_skipped(): void
    {
        [$service] = $this->service(null, static function (): void {
            throw new PlaidNetworkException('timeout');
        });
        $this->assertRejected('key_unavailable', $service, self::BODY, self::jwt(self::BODY));
    }

    public function test_key_fetches_are_rate_limited(): void
    {
        [$service] = $this->service(null, static function (): void {
            throw new PlaidApiException(400, 'INVALID_INPUT', 'INVALID_WEBHOOK_VERIFICATION_KEY_ID', 'unknown key', '', 'r');
        });
        $rejected = array();
        for ($i = 0; $i < 12; ++$i) {
            try {
                $service->verify(self::BODY, self::jwt(self::BODY, array(), null, 'kid-' . $i));
            } catch (WebhookVerificationException $exception) {
                $rejected[] = $exception->reason;
            }
        }
        self::assertContains('key_fetch_rate_limited', $rejected);
    }

    public function test_malformed_and_missing_headers_are_rejected(): void
    {
        [$service] = $this->service();
        $this->assertRejected('missing_header', $service, self::BODY, '');
        $this->assertRejected('malformed_jwt', $service, self::BODY, 'not-a-jwt');
        $this->assertRejected('malformed_jwt', $service, self::BODY, '%%%.abc.def');
        $this->assertRejected('malformed_jwt', $service, self::BODY, str_repeat('a', 5000) . '.b.c');
    }

    public function test_oversized_body_is_rejected(): void
    {
        [$service] = $this->service();
        $body = str_repeat('x', WebhookVerificationService::MAX_BODY_BYTES + 1);
        $this->assertRejected('oversized_body', $service, $body, self::jwt($body));
    }

    public function test_stale_and_future_tokens_are_rejected(): void
    {
        [$service] = $this->service();
        $this->assertRejected('stale_token', $service, self::BODY, self::jwt(self::BODY, array('iat' => time() - 301)));
        [$future] = $this->service();
        $rejected = '';
        try {
            $future->verify(self::BODY, self::jwt(self::BODY, array('iat' => time() + 3600)));
        } catch (WebhookVerificationException $exception) {
            $rejected = $exception->reason;
        }
        self::assertContains($rejected, array('stale_token', 'invalid_signature'));
    }

    public function test_expired_or_wrong_shape_keys_are_rejected(): void
    {
        [$expired] = $this->service(array('expired_at' => time() - 10) + self::$jwk);
        $this->assertRejected('key_expired', $expired, self::BODY, self::jwt(self::BODY));
        [$rsa] = $this->service(array('kty' => 'RSA') + self::$jwk);
        $this->assertRejected('invalid_key', $rsa, self::BODY, self::jwt(self::BODY));
    }

    public function test_non_json_body_with_valid_signature_is_rejected(): void
    {
        [$service] = $this->service();
        $this->assertRejected('malformed_body', $service, 'not json', self::jwt('not json'));
    }
}
