<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Logging;

/**
 * Recursively removes secrets and sensitive personal/banking values from log
 * context before it reaches any log sink.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /** Key fragments whose values are never safe to log. */
    private const SENSITIVE_FRAGMENTS = array(
        'secret',
        'password',
        'passwd',
        'access_token',
        'public_token',
        'link_token',
        'processor_token',
        'authorization',
        'cookie',
        'plaid-verification',
        'account_number',
        'routing_number',
        'wire_routing',
        'legal_name',
        'email',
        'phone',
        'address',
        'street',
        'postal_code',
        'ssn',
        'jwt',
        'signature',
        'api_key',
        'private_key',
    );

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value, int $depth = 0)
    {
        if ($depth > 8) {
            return self::REDACTED;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $result = array();
        foreach ($value as $key => $item) {
            if (is_string($key) && self::is_sensitive_key($key)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            $result[$key] = self::redact($item, $depth + 1);
        }
        return $result;
    }

    public static function is_sensitive_key(string $key): bool
    {
        $normal = strtolower(str_replace(array('-', ' '), '_', $key));
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normal, str_replace('-', '_', $fragment))) {
                return true;
            }
        }
        return false;
    }
}
