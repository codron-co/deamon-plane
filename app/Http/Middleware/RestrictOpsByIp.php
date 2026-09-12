<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictOpsByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs([
            'ops.platform-mail.unsubscribe',
            'ops.platform-mail.unsubscribe.post',
        ])) {
            return $next($request);
        }

        $allowed = $this->allowlist();
        if ($allowed === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();
        if ($ip !== '' && in_array($ip, $allowed, true)) {
            return $next($request);
        }

        abort(403, 'This Plane host is restricted to the configured ops IP allowlist.');
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        $raw = (string) config('ops.access.ip_allowlist', '');
        if (trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $ip): string => trim($ip),
            explode(',', $raw),
        )));
    }
}
