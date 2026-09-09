<?php

namespace App\Support;

use RuntimeException;

final class ProductionDebugGuard
{
    public static function assert(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (filter_var(config('app.debug'), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('APP_DEBUG must be false in production.');
        }
    }
}
