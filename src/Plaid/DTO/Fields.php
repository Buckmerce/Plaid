<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Plaid\DTO;

use Buckmerce\Plaid\Plaid\Exception\PlaidMalformedResponseException;

/** Strict field readers used to validate Plaid response objects before use. */
final class Fields
{
    /** @param array<string, mixed> $data */
    public static function required_string(array $data, string $key, string $request_id): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || '' === $value) {
            throw new PlaidMalformedResponseException(sprintf('Plaid object is missing "%s".', esc_html($key)), esc_html($request_id));
        }
        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function optional_string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : '';
    }

    /**
     * A calendar date (YYYY-MM-DD) such as a return window, or '' when absent or not a
     * valid date. Invalid provider dates are dropped instead of being trusted.
     *
     * @param array<string, mixed> $data
     */
    public static function optional_date(array $data, string $key): string
    {
        $value = self::optional_string($data, $key);
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return '';
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function optional_object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        return is_array($value) ? $value : array();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public static function string_map(array $data, string $key): array
    {
        $map = array();
        foreach (self::optional_object($data, $key) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $map[$name] = $value;
            }
        }
        return $map;
    }

    /** @param list<string> $allowed */
    public static function enum(string $value, array $allowed, string $field, string $request_id): string
    {
        if (! in_array($value, $allowed, true)) {
            throw new PlaidMalformedResponseException(sprintf('Plaid object has an unexpected "%s" value.', esc_html($field)), esc_html($request_id));
        }
        return $value;
    }
}
