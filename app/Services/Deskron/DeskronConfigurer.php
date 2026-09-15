<?php

namespace App\Services\Deskron;

use App\Models\DeskronSetting;
use App\Models\Site;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes the shared DeskRon application to one CMS over the signed agent
 * (POST /deskron/configure). No env, no redeploy.
 */
final class DeskronConfigurer
{
    use RetriesThrottledAgentRequests;

    /**
     * True when the CMS accepted the push. The outcome lands on
     * sites.deskron_pushed_at / deskron_push_failed_at for the panel.
     */
    public function sync(Site $site): bool
    {
        if (! $site->hasAgentSecret()) {
            return false;
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            $this->recordOutcome($site, 'no_base_url');

            return false;
        }

        $settings = DeskronSetting::current();
        $enabled = $settings->exists && $settings->isReady();

        $payload = ['enabled' => $enabled];
        if ($enabled) {
            $payload['application_id'] = (string) $settings->application_id;
            $payload['master_key'] = (string) $settings->api_key;
            $payload['webhook_secret'] = (string) ($settings->webhook_secret ?? '');
        }

        $body = ControlPlaneAgentContract::encodeJson($payload);
        if ($body === '') {
            $this->recordOutcome($site, 'encode_failed');

            return false;
        }

        $signed = ControlPlaneAgentSignature::headers((string) $site->agent_secret_encrypted, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));

        try {
            $response = $this->sendWithRetry(
                fn () => Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($signed['headers'])
                    ->withBody($body, 'application/json')
                    ->post($baseUrl.ControlPlaneAgentContract::deskronConfigurePath()),
                retryConnection: true,
            );
        } catch (ConnectionException) {
            $this->recordOutcome($site, 'timeout');

            return false;
        } catch (Throwable) {
            $this->recordOutcome($site, 'http_error');

            return false;
        }

        if ($response->failed()) {
            $this->recordOutcome($site, 'http_'.$response->status());

            return false;
        }

        $this->recordOutcome($site, null);

        return true;
    }

    private function recordOutcome(Site $site, ?string $reason): void
    {
        $attributes = $reason === null
            ? ['deskron_pushed_at' => now(), 'deskron_push_failed_at' => null, 'deskron_push_error' => null]
            : ['deskron_push_failed_at' => now(), 'deskron_push_error' => $reason];

        Site::query()->whereKey($site->getKey())->update($attributes);

        if ($reason !== null) {
            Log::warning('DeskRon configure push failed', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
                'reason' => $reason,
            ]);
        }
    }
}
