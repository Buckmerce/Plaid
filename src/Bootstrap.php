<?php

declare(strict_types=1);

namespace PayBridge\Plaid;

use PayBridge\Plaid\Support\Requirements;

final class Bootstrap
{
    public static function boot(): void
    {
        load_plugin_textdomain('paybridge-for-plaid', false, dirname(plugin_basename(PAYBRIDGE_PLAID_FILE)) . '/languages');
        $requirements = new Requirements();
        if (! $requirements->is_met()) {
            $requirements->register_notice();
            return;
        }
        ( new Plugin() )->register();
    }
}
