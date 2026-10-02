<?php

declare(strict_types=1);

namespace Buckmerce\Plaid;

use Buckmerce\Plaid\Support\Requirements;

final class Bootstrap
{
    public static function boot(): void
    {
        $requirements = new Requirements();
        if (! $requirements->is_met()) {
            $requirements->register_notice();
            return;
        }
        ( new Plugin() )->register();
    }
}
