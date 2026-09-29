<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Webhook;

use PayBridge\Plaid\Plaid\Exception\WebhookVerificationException;
use PayBridge\Plaid\Vendor\Firebase\JWT\JWK;
use PayBridge\Plaid\Vendor\Firebase\JWT\JWT;
use PayBridge\Plaid\Vendor\Firebase\JWT\Key;

/**
 * Verifies the Plaid-Verification JWT exactly as documented in
 * docs/api/api/webhooks/webhook-verification.md:
 *
 * 1. header alg must be ES256 (never trusted dynamically);
 * 2. kid selects a key from /webhook_verification_key/get;
 * 3. signature verified by a maintained JWT library (no hand-rolled crypto);
 * 4. iat no older than 5 minutes;
 * 5. SHA-256 of the exact raw body equals request_body_sha256 (constant time).
 */
final class WebhookVerificationService
{
    public const MAX_BODY_BYTES = 65536;
    public const MAX_TOKEN_AGE_SECONDS = 300;
    public const CLOCK_SKEW_SECONDS = 60;

    public function __construct(private readonly VerificationKeyProvider $keys, private readonly ?\Closure $clock = null)
    {
    }

    /**
     * @return array<string, mixed> The verified, decoded webhook body.
     * @throws WebhookVerificationException
     */
    public function verify(string $raw_body, string $jwt): array
    {
        if ('' === $jwt) {
            throw new WebhookVerificationException('missing_header');
        }
        if (strlen($raw_body) > self::MAX_BODY_BYTES) {
            throw new WebhookVerificationException('oversized_body');
        }
        if (strlen($jwt) > 4096 || 2 !== substr_count($jwt, '.')) {
            throw new WebhookVerificationException('malformed_jwt');
        }
        [$encoded_header] = explode('.', $jwt, 2);
        $header = json_decode(self::base64url_decode($encoded_header), true);
        if (! is_array($header)) {
            throw new WebhookVerificationException('malformed_jwt');
        }
        if ('ES256' !== ($header['alg'] ?? null)) {
            throw new WebhookVerificationException('wrong_alg');
        }
        $key_id = $header['kid'] ?? null;
        if (! is_string($key_id) || ! preg_match('/^[A-Za-z0-9_\-]{1,128}$/', $key_id)) {
            throw new WebhookVerificationException('malformed_jwt');
        }

        $jwk = $this->keys->get($key_id);
        $now = null === $this->clock ? time() : (int) ($this->clock)();
        try {
            $key = JWK::parseKey(array('kty' => 'EC', 'crv' => 'P-256', 'x' => $jwk['x'], 'y' => $jwk['y'], 'alg' => 'ES256', 'kid' => $key_id), 'ES256');
            if (! $key instanceof Key || 'ES256' !== $key->getAlgorithm()) {
                throw new WebhookVerificationException('invalid_key');
            }
            JWT::$leeway = self::CLOCK_SKEW_SECONDS;
            JWT::$timestamp = $now;
            // The Key's algorithm is pinned to ES256; the library rejects any header alg mismatch.
            $claims = (array) JWT::decode($jwt, $key);
        } catch (WebhookVerificationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new WebhookVerificationException('invalid_signature');
        } finally {
            JWT::$timestamp = null;
        }

        $issued_at = $claims['iat'] ?? null;
        if (! is_int($issued_at) || $issued_at < $now - self::MAX_TOKEN_AGE_SECONDS || $issued_at > $now + self::CLOCK_SKEW_SECONDS) {
            throw new WebhookVerificationException('stale_token');
        }
        $expected_hash = $claims['request_body_sha256'] ?? null;
        if (! is_string($expected_hash) || ! hash_equals(strtolower($expected_hash), hash('sha256', $raw_body))) {
            throw new WebhookVerificationException('body_hash_mismatch');
        }

        $body = json_decode($raw_body, true);
        if (! is_array($body)) {
            throw new WebhookVerificationException('malformed_body');
        }
        return $body;
    }

    private static function base64url_decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return false === $decoded ? '' : $decoded;
    }
}
