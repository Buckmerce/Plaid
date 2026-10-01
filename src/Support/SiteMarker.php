<?php

declare(strict_types=1);

namespace Buckmerce\Plaid\Support;

/**
 * Short, non-reversible identifier of this store, written into Transfer Intent
 * metadata. Several stores (or other integrations) can share one Plaid account
 * and therefore one /transfer/event/sync stream; the marker lets Buckmerce
 * recognise transfers that belong to another store instead of guessing by
 * order number.
 */
final class SiteMarker
{
    public static function current(): string
    {
        return substr(hash_hmac('sha256', 'buckmerce-plaid-site:' . home_url('/'), wp_salt('auth')), 0, 16);
    }
}
