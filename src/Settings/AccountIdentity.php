<?php

declare(strict_types=1);

namespace PayBridge\Plaid\Settings;

/**
 * Non-secret identity of a Plaid account (ADR-0015).
 *
 * Plaid issues one client_id per team; the Sandbox and Production secrets both belong to
 * it, and rotating a secret keeps the client_id. Transfers are readable only by the client
 * that created them, so the client_id — not the secret, and not the environment name — is
 * what decides whether an existing payment can still be monitored. Only a one-way hash is
 * stored next to payments; the secret is never part of it.
 */
final class AccountIdentity
{
    public static function fingerprint(string $client_id): string
    {
        $client_id = strtolower(trim($client_id));
        return '' === $client_id ? '' : substr(hash('sha256', 'paybridge-plaid-account:' . $client_id), 0, 16);
    }
}
