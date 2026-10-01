<?php

declare(strict_types=1);

namespace Buckmerce\Plaid;

use Buckmerce\Plaid\Support\Requirements;

final class Bootstrap
{
    public static function boot(): void
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Released outside WordPress.org; registers the bundled languages/ directory.
        load_plugin_textdomain('buckmerce-for-plaid', false, dirname(plugin_basename(BUCKMERCE_PLAID_FILE)) . '/languages');
        $requirements = new Requirements();
        if (! $requirements->is_met()) {
            $requirements->register_notice();
            return;
        }
        ( new Plugin() )->register();
    }
}
