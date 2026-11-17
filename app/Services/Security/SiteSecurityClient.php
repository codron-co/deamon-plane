<?php

namespace App\Services\Security;

use App\Models\Site;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signed calls to a CMS's `security-alerts` agent routes (HMAC like the health poll).
 * GET summary reads, POST block / unblock / allowlist write; every write carries a
 * `uid` the CMS dedupes on, so a retry or a second sweep is harmless.
 *
 * The client keeps request bodies out of its logs and drops any answer that echoes
 * the site's agent secret.
 */
class SiteSecurityClient
{
    use RetriesThrottledAgentRequests;

    public function summary(Site $site, ?string $since = null): SecurityCallResult
    {
        $query = $since !== null && $since !== '' ? '?since='.rawurlencode($since) : '';

        return $this->send($site, 'GET', ControlPlaneAgentContract::SECURITY_SUMMARY_PATH, null, $query);
    }

    /**
     * @param  array<string, mixed>  $body  uid, ip, reason, expires_at, source
     */
    public function block(Site $site, array $body): SecurityCallResult
    {
        return $this->send($site, 'POST', ControlPlaneAgentContract::SECURITY_BLOCK_PATH, $body);
    }

    /**
     * @param  array<string, mixed>  $body  uid, ip
     */
    public function unblock(Site $site, array $body): SecurityCallResult
    {
        return $this->send($site, 'POST', ControlPlaneAgentContract::SECURITY_UNBLOCK_PATH, $body);
    }

    /**
     * @param  array<string, mixed>  $body  uid, action (add|remove), ip, label
     */
    public function allowlist(Site $site, array $body): SecurityCallResult
    {
        return $this->send($site, 'POST', ControlPlaneAgentContract::SECURITY_ALLOWLIST_PATH, $body);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function send(Site $site, string $method, string $path, ?array $body, string $query = ''): SecurityCallResult
    {
        if (! $site->hasAgentSecret()) {
            return SecurityCallResult::failure(SecurityCallResult::NEEDS_SECRET, 'Site has no agent secret.');
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'Site has no agent base URL.');
        }

        $raw = '';
        if ($body !== null) {
            $raw = ControlPlaneAgentContract::encodeJson($body);
            if ($raw === '') {
                return SecurityCallResult::failure(SecurityCallResult::FAILED, 'Request body could not be encoded.');
            }
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, $raw);
        $timeout = max(1, (int) config('ops.security.timeout_seconds', 8));
        $url = $baseUrl.$path.$query;

        try {
            $response = $this->sendWithRetry(function () use ($method, $url, $signed, $raw, $timeout): Response {
                $pending = Http::timeout($timeout)->acceptJson()->withHeaders($signed['headers']);

                return $method === 'GET'
                    ? $pending->get($url)
                    : $pending->withBody($raw, 'application/json')->post($url);
            });
        } catch (ConnectionException) {
            $this->log($site, $path, 'timeout');

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'Security request timed out.');
        } catch (Throwable) {
            $this->log($site, $path, 'http_error');

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'Security request failed.');
        }

        $status = $response->status();
        if ($status === 429) {
            return SecurityCallResult::failure(SecurityCallResult::RATE_LIMITED, 'CMS throttled the security request.', $status);
        }
        if ($status === 404 || $status === 405) {
            return SecurityCallResult::failure(SecurityCallResult::UNSUPPORTED, 'The CMS has no security endpoint.', $status);
        }
        if (in_array($status, [401, 403], true)) {
            $this->log($site, $path, 'bad_signature', $status);

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'The CMS rejected the signature.', $status);
        }
        if ($response->failed()) {
            $this->log($site, $path, 'http_error', $status);

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'The CMS answered HTTP '.$status.'.', $status);
        }

        $json = $response->json();
        if (! is_array($json)) {
            $this->log($site, $path, 'not_json', $status);

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'The CMS answered with a non-JSON body.', $status);
        }

        $encoded = json_encode($json, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && str_contains($encoded, $secret)) {
            $this->log($site, $path, 'secret_echo', $status);

            return SecurityCallResult::failure(SecurityCallResult::FAILED, 'Security payload was discarded.', $status);
        }

        if (($json['ok'] ?? true) === false) {
            return SecurityCallResult::failure(SecurityCallResult::FAILED, mb_substr((string) ($json['message'] ?? 'The CMS refused the request.'), 0, 200), $status);
        }

        return SecurityCallResult::ok($json, $status);
    }

    private function log(Site $site, string $path, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Site security agent call failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'path' => $path,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }
}
