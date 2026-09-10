<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteMailSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $siteId = trim((string) $request->header(ControlPlaneAgentContract::HEADER_SITE, ''));
        if ($siteId === '' || preg_match('~\A[0-9A-HJKMNP-TV-Z]{26}\z~i', $siteId) !== 1) {
            return $this->denied();
        }

        $site = Site::query()->find($siteId);
        if (! $site instanceof Site || ! $site->hasAgentSecret()) {
            return $this->denied();
        }

        $timestamp = (string) $request->header(ControlPlaneAgentContract::HEADER_TIMESTAMP, '');
        $nonce = (string) $request->header(ControlPlaneAgentContract::HEADER_NONCE, '');
        $signature = $request->header(ControlPlaneAgentContract::HEADER_SIGNATURE);

        if (! $this->timestampIsFresh($timestamp) || ! $this->nonceIsWellFormed($nonce)) {
            return $this->denied();
        }

        if ($this->nonceWasUsed($site, $nonce)) {
            return $this->denied();
        }

        $body = (string) $request->getContent();
        $secret = (string) $site->agent_secret_encrypted;
        if (! ControlPlaneAgentSignature::matches($secret, $timestamp, $nonce, $body, is_string($signature) ? $signature : null)) {
            return $this->denied();
        }

        $this->rememberNonce($site, $nonce);
        $request->attributes->set('mailSite', $site);

        return $next($request);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $skew = max(1, (int) config('ops.agent.skew_seconds', 60));

        return abs(now()->timestamp - (int) $timestamp) <= $skew;
    }

    private function nonceIsWellFormed(string $nonce): bool
    {
        $length = strlen($nonce);
        if ($length < ControlPlaneAgentContract::NONCE_MIN_LENGTH || $length > ControlPlaneAgentContract::NONCE_MAX_LENGTH) {
            return false;
        }

        return preg_match('/\A[A-Za-z0-9._-]+\z/', $nonce) === 1;
    }

    private function nonceWasUsed(Site $site, string $nonce): bool
    {
        return Cache::has($this->nonceCacheKey($site, $nonce));
    }

    private function rememberNonce(Site $site, string $nonce): void
    {
        $ttl = max(30, (int) config('ops.agent.nonce_ttl_seconds', 120));
        Cache::put($this->nonceCacheKey($site, $nonce), 1, $ttl);
    }

    private function nonceCacheKey(Site $site, string $nonce): string
    {
        return 'site-mail:nonce:'.$site->id.':'.hash('sha256', $nonce);
    }

    private function denied(): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'unauthorized',
            'message' => 'Request signature was rejected.',
        ], 401);
    }
}
