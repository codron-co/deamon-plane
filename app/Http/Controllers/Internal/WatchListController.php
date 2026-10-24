<?php

namespace App\Http\Controllers\Internal;

use App\Enums\SiteStatus;
use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Active site domains for the off-host uptime watch (tools/ops/deamon-watch.sh),
 * one per line. Bearer token from `ops.watch.token`; no token configured = 404.
 */
class WatchListController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('ops.watch.token');
        $given = (string) $request->bearerToken();

        if ($token === '') {
            abort(404);
        }

        if ($given === '' || ! hash_equals($token, $given)) {
            abort(401);
        }

        $domains = Site::query()
            ->where('status', SiteStatus::Active)
            ->whereNotNull('primary_domain')
            ->orderBy('primary_domain')
            ->pluck('primary_domain')
            ->map(fn (mixed $domain): string => strtolower(trim((string) $domain)))
            ->filter(fn (string $domain): bool => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) === 1)
            ->unique()
            ->values();

        return response($domains->implode("\n")."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
