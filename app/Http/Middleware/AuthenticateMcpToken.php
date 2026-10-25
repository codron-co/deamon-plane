<?php

namespace App\Http\Middleware;

use App\Models\McpToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer token auth for /mcp. The endpoint has no session or CSRF; the token is
 * the only credential, so it is checked here before anything else runs.
 * OPS_MCP_IP_ALLOWLIST (comma-separated) optionally limits where tokens work.
 */
class AuthenticateMcpToken
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ops.mcp.enabled', true)) {
            return $this->deny(404, 'mcp_disabled', 'The Plane MCP endpoint is disabled.');
        }

        $allowlist = array_values(array_filter(array_map('trim', explode(',', (string) config('ops.mcp.ip_allowlist', '')))));
        if ($allowlist !== [] && ! in_array((string) $request->ip(), $allowlist, true)) {
            return $this->deny(403, 'ip_not_allowed', 'This IP may not use Plane MCP tokens.');
        }

        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? McpToken::findActive($plain) : null;
        if (! $token instanceof McpToken) {
            return $this->deny(401, 'token_required', 'A valid Plane MCP token is required: Authorization: Bearer plane_mcp_…');
        }

        // Throttle the last-used write: an agent makes many calls per minute.
        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute()) || $token->last_used_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        $user = $token->owner();
        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('mcp_token', $token);
        App::setLocale($user->localeValue());

        return $next($request);
    }

    private function deny(int $status, string $code, string $message): Response
    {
        return response()->json(['ok' => false, 'code' => $code, 'message' => $message], $status, [
            'WWW-Authenticate' => 'Bearer realm="deamon-plane-mcp"',
        ]);
    }
}
