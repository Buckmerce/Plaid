<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Plaid\Exception;

/** Plaid returned a structured error object (error_type / error_code). */
final class PlaidApiException extends PlaidException
{
    public function __construct(
        public readonly int $http_status,
        public readonly string $error_type,
        public readonly string $error_code,
        string $error_message,
        public readonly string $display_message = '',
        string $request_id = ''
    ) {
        parent::__construct(sprintf('Plaid API error %s/%s (HTTP %d): %s', $error_type, $error_code, $http_status, $error_message), $request_id);
    }

    public function is_ambiguous(): bool
    {
        // API_ERROR (e.g. INTERNAL_SERVER_ERROR) and 5xx responses do not prove
        // that Plaid rejected the request before performing it.
        return $this->http_status >= 500 || 'API_ERROR' === $this->error_type;
    }

    public function safe_code(): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9_]/', '', $this->error_code) ?? 'plaid_api_error');
    }
}
