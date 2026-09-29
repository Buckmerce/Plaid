<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid;

use PayBridge\Plaid\Exception\ConfigurationException;

/**
 * The two supported Plaid environments. Hosts are fixed constants so no
 * request data or setting can redirect API traffic (SSRF protection).
 */
final class PlaidEnvironment
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    private const HOSTS = array(
        self::SANDBOX => 'https://sandbox.plaid.com',
        self::PRODUCTION => 'https://production.plaid.com',
    );

    private function __construct(public readonly string $name)
    {
    }

    /** @throws ConfigurationException */
    public static function from_string(string $name): self
    {
        if (! isset(self::HOSTS[$name])) {
            throw new ConfigurationException('Unsupported Plaid environment.');
        }
        return new self($name);
    }

    public static function is_valid(string $name): bool
    {
        return isset(self::HOSTS[$name]);
    }

    public function base_url(): string
    {
        return self::HOSTS[$this->name];
    }

    public function is_production(): bool
    {
        return self::PRODUCTION === $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }
}
