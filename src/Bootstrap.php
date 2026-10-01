<?php

declare(strict_types=1);

namespace Buckmerce\Plaid;

use Buckmerce\Plaid\Support\Requirements;

final class Bootstrap
{
    public static function boot(): void
    {
        // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- The plugin ships its own translations (ru_RU) in languages/; this registers that directory.
        load_plugin_textdomain('buckmerce-plaid', false, dirname(plugin_basename(BUCKMERCE_PLAID_FILE)) . '/languages');
        $requirements = new Requirements();
        if (! $requirements->is_met()) {
            $requirements->register_notice();
            return;
        }
        ( new Plugin() )->register();
    }
}
