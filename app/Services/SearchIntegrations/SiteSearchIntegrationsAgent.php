<?php

namespace App\Services\SearchIntegrations;

use App\Models\Site;
use App\Models\SiteSearchIntegration;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes (POST) and reads (GET) a site's search verification + measurement ids
 * over the signed agent (`/internal/control/v1/search-integrations`). No env,
 * no redeploy. The push outcome lands on `site_search_integrations` for the panel.
 */
final class SiteSearchIntegrationsAgent
{
    use RetriesThrottledAgentRequests;

    /**
     * True when the CMS accepted the push.
     */
    public function push(Site $site, bool $retryConnection = false): bool
    {
        $record = $site->searchIntegration()->first();
        if (! $record instanceof SiteSearchIntegration) {
            return false;
        }

        $request = $this->prepare($site);
        if (is_string($request)) {
            $this->recordPush($record, $request);

            return false;
        }

        $body = ControlPlaneAgentContract::encodeJson($record->pushPayload());
        if ($body === '' || $body === '[]') {
            // Nothing managed yet and no module switch: an empty object is still a valid no-op push.
            $body = '{}';
        }

        $signed = ControlPlaneAgentSignature::headers($request['secret'], $body);

        try {
            $response = $this->sendWithRetry(
                fn (): Response => Http::timeout($request['timeout'])
                    ->acceptJson()
                    ->withHeaders($signed['headers'])
                    ->withBody($body, 'application/json')
                    ->post($request['url']),
                retryConnection: $retryConnection,
            );
        } catch (ConnectionException) {
            $this->recordPush($record, 'timeout');

            return false;
        } catch (Throwable) {
            $this->recordPush($record, 'http_error');

            return false;
        }

        $json = $response->json();
        if ($response->failed() || ! is_array($json) || ($json['ok'] ?? false) !== true) {
            $this->recordPush($record, $this->failureReason($response, is_array($json) ? $json : null));

            return false;
        }

        $this->recordPush($record, null, isset($json['module_enabled']) ? (bool) $json['module_enabled'] : null);

        return true;
    }

    /**
     * Reads the site's current values. Never writes the Plane record.
     *
     * @return array{ok: bool, error?: string, values?: array<string, string>, module_enabled?: bool|null, last_changed_at?: string|null}
     */
    public function pull(Site $site): array
    {
        $request = $this->prepare($site);
        if (is_string($request)) {
            return ['ok' => false, 'error' => $request];
        }

        $signed = ControlPlaneAgentSignature::headers($request['secret'], '');

        try {
            $response = $this->sendWithRetry(fn (): Response => Http::timeout($request['timeout'])
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->get($request['url']));
        } catch (ConnectionException) {
            return $this->pullFailure($site, 'timeout');
        } catch (Throwable) {
            return $this->pullFailure($site, 'http_error');
        }

        $json = $response->json();
        if ($response->failed() || ! is_array($json) || ($json['ok'] ?? false) !== true
            || ! is_array($json['search_integrations'] ?? null)) {
            return $this->pullFailure($site, $this->failureReason($response, is_array($json) ? $json : null));
        }

        return [
            'ok' => true,
            'values' => SiteSearchIntegration::valuesFromRemote($json['search_integrations']),
            'module_enabled' => isset($json['module_enabled']) ? (bool) $json['module_enabled'] : null,
            'last_changed_at' => is_string($json['last_changed_at'] ?? null) ? $json['last_changed_at'] : null,
        ];
    }

    /**
     * @return array{url: string, secret: string, timeout: int}|string
     */
    private function prepare(Site $site): array|string
    {
        if (! $site->hasAgentSecret()) {
            return 'no_agent_secret';
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return 'no_base_url';
        }

        return [
            'url' => $baseUrl.ControlPlaneAgentContract::searchIntegrationsPath(),
            'secret' => (string) $site->agent_secret_encrypted,
            'timeout' => max(1, (int) config('ops.agent.timeout_seconds', 10)),
        ];
    }

    /**
     * `http_404` / `http_405` mean the CMS is older than the endpoint; a CMS 422
     * carries its own reason (`validation_failed: GA4 …`).
     *
     * @param  array<string, mixed>|null  $json
     */
    private function failureReason(Response $response, ?array $json): string
    {
        $error = is_string($json['error'] ?? null) ? $json['error'] : null;
        if ($error !== null && $response->status() === 422) {
            $message = is_string($json['message'] ?? null) ? trim($json['message']) : '';

            return Str::limit($message !== '' ? $error.': '.$message : $error, 250, '…');
        }

        return $response->failed() ? 'http_'.$response->status() : 'invalid_response';
    }

    private function recordPush(SiteSearchIntegration $record, ?string $reason, ?bool $moduleEnabled = null): void
    {
        $attributes = $reason === null
            ? ['pushed_at' => now(), 'push_failed_at' => null, 'push_error' => null]
            : ['push_failed_at' => now(), 'push_error' => $reason];

        if ($moduleEnabled !== null) {
            $attributes['site_module_enabled'] = $moduleEnabled;
        }

        // Base query: the outcome is not an operator change, so updated_at stays put.
        SiteSearchIntegration::query()->whereKey($record->getKey())->toBase()->update($attributes);

        if ($reason !== null) {
            Log::warning('Search integrations push failed', [
                'site_id' => $record->site_id,
                'reason' => $reason,
            ]);
        }
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function pullFailure(Site $site, string $reason): array
    {
        Log::warning('Search integrations pull failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'reason' => $reason,
        ]);

        return ['ok' => false, 'error' => $reason];
    }
}
