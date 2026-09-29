<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Client;

use PayBridge\Plaid\Plaid\Exception\PlaidMalformedResponseException;

/** Successful (HTTP 200) decoded Plaid response. */
final class PlaidResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(public readonly array $data, public readonly string $request_id)
    {
    }

    /** @return array<string, mixed> */
    public function object(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (! is_array($value)) {
            throw new PlaidMalformedResponseException(sprintf('Plaid response is missing object "%s".', esc_html($key)), esc_html($this->request_id));
        }
        return $value;
    }

    /** @return list<mixed> */
    public function list(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new PlaidMalformedResponseException(sprintf('Plaid response is missing list "%s".', esc_html($key)), esc_html($this->request_id));
        }
        return $value;
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;
        if (! is_string($value) || '' === $value) {
            throw new PlaidMalformedResponseException(sprintf('Plaid response is missing string "%s".', esc_html($key)), esc_html($this->request_id));
        }
        return $value;
    }
}
