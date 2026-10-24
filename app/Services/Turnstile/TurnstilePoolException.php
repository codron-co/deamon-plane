<?php

namespace App\Services\Turnstile;

use RuntimeException;

/**
 * Havuz kararı verilemedi. Mesaj bir kod: `no_hostnames`, `too_many_hostnames`,
 * `widget_limit`, `invalid_cloudflare_response` (lang: turnstile.errors.*).
 */
final class TurnstilePoolException extends RuntimeException {}
