<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Payment;

use InvalidArgumentException;
use PayBridge\Plaid\Plaid\PlaidEnvironment;
use PayBridge\Plaid\Settings\Settings;
use PayBridge\Plaid\Support\Decimal;
use PayBridge\Plaid\Support\Money;

/**
 * Immutable record of what one payment attempt is expected to charge. It is
 * written before any remote call and never mutated afterwards; provider data
 * is always compared against it.
 */
final class PaymentSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly string $attempt_id,
        public readonly int $order_id,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $environment,
        public readonly string $gateway_id,
        public readonly string $created_at
    ) {
        if (! preg_match('/^[a-f0-9]{32}$/', $attempt_id)) {
            throw new InvalidArgumentException('Invalid payment attempt identifier.');
        }
        if ($order_id < 1) {
            throw new InvalidArgumentException('Invalid order identifier.');
        }
        if (! preg_match('/^(?:0|[1-9][0-9]*)\.[0-9]{2}$/', $amount) || 0 === Decimal::compare($amount, '0')) {
            throw new InvalidArgumentException('Snapshot amount must be a positive two-decimal string.');
        }
        if (Money::SUPPORTED_CURRENCY !== $currency) {
            throw new InvalidArgumentException('Unsupported snapshot currency.');
        }
        if (! PlaidEnvironment::is_valid($environment)) {
            throw new InvalidArgumentException('Invalid snapshot environment.');
        }
        if (Settings::GATEWAY_ID !== $gateway_id) {
            throw new InvalidArgumentException('Snapshot belongs to another gateway.');
        }
        if (false === strtotime($created_at)) {
            throw new InvalidArgumentException('Invalid snapshot timestamp.');
        }
    }

    public static function create(int $order_id, string $amount, string $currency, string $environment): self
    {
        return new self(bin2hex(random_bytes(16)), $order_id, $amount, $currency, $environment, Settings::GATEWAY_ID, gmdate('c'));
    }

    /** @return array{schema_version:int, attempt_id:string, order_id:int, amount:string, currency:string, environment:string, gateway_id:string, created_at:string} */
    public function to_array(): array
    {
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'attempt_id' => $this->attempt_id,
            'order_id' => $this->order_id,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'environment' => $this->environment,
            'gateway_id' => $this->gateway_id,
            'created_at' => $this->created_at,
        );
    }

    public function to_json(): string
    {
        return (string) json_encode($this->to_array(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->to_json());
    }

    public static function from_json(string $json): ?self
    {
        if ('' === $json) {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? self::from_array($data) : null;
    }

    /** @param array<string, mixed> $data */
    public static function from_array(array $data): ?self
    {
        if (self::SCHEMA_VERSION !== ($data['schema_version'] ?? null)) {
            return null;
        }
        foreach (array('attempt_id', 'amount', 'currency', 'environment', 'gateway_id', 'created_at') as $key) {
            if (! is_string($data[$key] ?? null)) {
                return null;
            }
        }
        if (! is_int($data['order_id'] ?? null)) {
            return null;
        }
        try {
            return new self($data['attempt_id'], $data['order_id'], $data['amount'], $data['currency'], $data['environment'], $data['gateway_id'], $data['created_at']);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** Whether the snapshot still describes the order as WooCommerce currently stores it. */
    public function matches(int $order_id, string $amount, string $currency, string $environment): bool
    {
        return $this->order_id === $order_id
            && Money::same_amount($this->amount, $amount)
            && $this->currency === strtoupper($currency)
            && $this->environment === $environment;
    }
}
