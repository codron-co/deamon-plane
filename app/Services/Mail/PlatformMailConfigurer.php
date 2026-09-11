<?php

namespace App\Services\Mail;

use App\Models\PlatformMailSetting;
use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PlatformMailConfigurer
{
    public function __construct(
        private readonly PlatformMailResolver $resolver,
    ) {}

    public function sync(Site $site): SiteMailConfigureResult
    {
        if (! $site->hasAgentSecret()) {
            return SiteMailConfigureResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return SiteMailConfigureResult::failure('Site has no agent base URL or primary domain.');
        }

        $settings = $this->resolver->settings();
        $enabled = $settings->exists && $settings->isReady();

        $payload = [
            'enabled' => $enabled,
            'site_id' => (string) $site->id,
            'plane_base_url' => rtrim((string) config('app.url'), '/'),
            'admin_recipient' => $this->resolver->recipientFor($site),
            'notifications' => $this->resolver->notificationsFor($site),
        ];

        if ($enabled) {
            $payload['smtp'] = [
                'host' => $settings->host,
                'port' => (int) $settings->port,
                'encryption' => $settings->encryption ?: 'ssl',
                'username' => $settings->username,
                'password' => $settings->password,
                'from_address' => $settings->from_address,
                'from_name' => $settings->from_name ?: $settings->from_address,
            ];
        }

        $body = ControlPlaneAgentContract::encodeJson($payload);
        if ($body === '') {
            return SiteMailConfigureResult::failure('Platform mail payload could not be encoded.');
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.ControlPlaneAgentContract::platformMailConfigurePath();

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            $this->logFailure($site, 'timeout');

            return SiteMailConfigureResult::failure('Platform mail configure timed out.');
        } catch (Throwable) {
            $this->logFailure($site, 'http_error');

            return SiteMailConfigureResult::failure('Platform mail configure request failed.');
        }

        if ($response->failed()) {
            $this->logFailure($site, 'http_error', $response->status());

            return SiteMailConfigureResult::failure(
                'Platform mail configure returned HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        return SiteMailConfigureResult::ok($enabled, $response->status());
    }

    public function syncAllSites(): int
    {
        $count = 0;
        Site::query()
            ->orderBy('id')
            ->each(function (Site $site) use (&$count): void {
                if (! $site->hasAgentSecret()) {
                    return;
                }
                $this->sync($site);
                $count++;
            });

        $settings = PlatformMailSetting::current();
        if ($settings->exists) {
            $settings->last_pushed_at = now();
            $settings->save();
        }

        return $count;
    }

    private function logFailure(Site $site, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Platform mail configure failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }
}
