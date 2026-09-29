<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Webhook;

use PayBridge\Plaid\Plaid\Client\PlaidClientInterface;
use PayBridge\Plaid\Plaid\Exception\PlaidApiException;
use PayBridge\Plaid\Plaid\Exception\PlaidException;
use PayBridge\Plaid\Plaid\Exception\WebhookVerificationException;

/**
 * Fetches and caches Plaid webhook verification JWKs by key ID via
 * /webhook_verification_key/get. Lookups for unknown key IDs are negatively
 * cached and globally rate limited so forged webhooks cannot turn the
 * endpoint into a Plaid API amplifier.
 */
final class VerificationKeyProvider
{
    private const CACHE_SECONDS = DAY_IN_SECONDS;
    private const NEGATIVE_CACHE_SECONDS = 300;
    private const MAX_FETCHES_PER_MINUTE = 10;

    /** @var \Closure(string): mixed */
    private readonly \Closure $cache_get;
    /** @var \Closure(string, mixed, int): bool */
    private readonly \Closure $cache_set;

    /**
     * @param (callable(string): mixed)|null           $cache_get
     * @param (callable(string, mixed, int): bool)|null $cache_set
     */
    public function __construct(
        private readonly PlaidClientInterface $client,
        ?callable $cache_get = null,
        ?callable $cache_set = null
    ) {
        $this->cache_get = null === $cache_get ? static fn (string $key) => get_transient($key) : \Closure::fromCallable($cache_get);
        $this->cache_set = null === $cache_set ? static fn (string $key, $value, int $ttl): bool => set_transient($key, $value, $ttl) : \Closure::fromCallable($cache_set);
    }

    /**
     * @return array<string, mixed> JWK with kty/crv/x/y/alg.
     * @throws WebhookVerificationException
     */
    public function get(string $key_id): array
    {
        $cache_key = 'pbfp_jwk_' . $this->client->environment()->name . '_' . substr(hash('sha256', $key_id), 0, 32);
        $cached = ($this->cache_get)($cache_key);
        if (is_array($cached)) {
            if (true === ($cached['unknown'] ?? false)) {
                throw new WebhookVerificationException('unknown_key');
            }
            return $this->usable($cached, $key_id);
        }

        $counter_key = 'pbfp_jwk_fetches_' . $this->client->environment()->name . '_' . gmdate('YmdHi');
        $fetches = ($this->cache_get)($counter_key);
        $fetches = is_int($fetches) ? $fetches : 0;
        if ($fetches >= self::MAX_FETCHES_PER_MINUTE) {
            throw new WebhookVerificationException('key_fetch_rate_limited');
        }
        ($this->cache_set)($counter_key, $fetches + 1, 120);

        try {
            $response = $this->client->post('/webhook_verification_key/get', array('key_id' => $key_id));
        } catch (PlaidApiException $exception) {
            if (! $exception->is_ambiguous()) {
                ($this->cache_set)($cache_key, array('unknown' => true), self::NEGATIVE_CACHE_SECONDS);
                throw new WebhookVerificationException('unknown_key');
            }
            throw new WebhookVerificationException('key_unavailable');
        } catch (PlaidException $exception) {
            // A key-fetch failure is never permission to skip verification.
            throw new WebhookVerificationException('key_unavailable');
        }
        $key = $response->data['key'] ?? null;
        if (! is_array($key)) {
            throw new WebhookVerificationException('key_unavailable');
        }
        $jwk = $this->usable($key, $key_id);
        ($this->cache_set)($cache_key, $jwk, self::CACHE_SECONDS);
        return $jwk;
    }

    /**
     * @param array<string, mixed> $key
     * @return array<string, mixed>
     * @throws WebhookVerificationException
     */
    private function usable(array $key, string $key_id): array
    {
        if (
            ($key['kid'] ?? null) !== $key_id
            || 'EC' !== ($key['kty'] ?? null)
            || 'P-256' !== ($key['crv'] ?? null)
            || 'ES256' !== ($key['alg'] ?? null)
            || ! is_string($key['x'] ?? null)
            || ! is_string($key['y'] ?? null)
        ) {
            throw new WebhookVerificationException('invalid_key');
        }
        $expired_at = $key['expired_at'] ?? null;
        if (is_int($expired_at) && $expired_at > 0 && $expired_at < time()) {
            throw new WebhookVerificationException('key_expired');
        }
        return array(
            'kid' => $key_id,
            'kty' => 'EC',
            'crv' => 'P-256',
            'alg' => 'ES256',
            'x' => $key['x'],
            'y' => $key['y'],
            'expired_at' => is_int($expired_at) ? $expired_at : null,
        );
    }
}
