<?php

namespace App\Services\Mail;

use App\Enums\MailProvider;
use App\Models\MailServer;
use App\Models\Site;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SiteMailConfigurer
{
    public function sync(Site $site): SiteMailConfigureResult
    {
        if (! $site->hasAgentSecret()) {
            return SiteMailConfigureResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return SiteMailConfigureResult::failure('Site has no agent base URL or primary domain.');
        }

        $site->loadMissing(['mailServer', 'mailBindings']);
        $mailServer = $site->mailServer;
        $domains = $site->mailDomains();
        $enabled = $mailServer instanceof MailServer
            && $mailServer->provider === MailProvider::Hostinger
            && $mailServer->isHostingerReady()
            && $domains !== [];

        $payload = $enabled
            ? [
                'enabled' => true,
                'provider' => MailProvider::Hostinger->value,
                'site_id' => (string) $site->id,
                'plane_base_url' => $this->planeBaseUrl(),
                'mail_domain' => $domains[0],
                'mail_domains' => $domains,
                'webmail_url' => (string) config('ops.hostinger.webmail_url', 'https://mail.hostinger.com'),
            ]
            : [
                'enabled' => false,
                'provider' => null,
                'site_id' => (string) $site->id,
                'plane_base_url' => $this->planeBaseUrl(),
            ];

        $body = ControlPlaneAgentContract::encodeJson($payload);
        if ($body === '') {
            return SiteMailConfigureResult::failure('Mail configure payload could not be encoded.');
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.ControlPlaneAgentContract::mailConfigurePath();

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            $this->logFailure($site, 'timeout');

            return SiteMailConfigureResult::failure('Mail configure timed out.');
        } catch (Throwable) {
            $this->logFailure($site, 'http_error');

            return SiteMailConfigureResult::failure('Mail configure request failed.');
        }

        if ($response->failed()) {
            $this->logFailure($site, 'http_error', $response->status());

            return SiteMailConfigureResult::failure(
                'Mail configure returned HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $json = $response->json();
        if (is_array($json) && $this->payloadContainsSecret($site, $json, $payload)) {
            $this->logFailure($site, 'secret_echo', $response->status());

            return SiteMailConfigureResult::failure('Mail configure payload was discarded.', $response->status());
        }

        return SiteMailConfigureResult::ok($enabled, $response->status());
    }

    public function syncAssignedSites(MailServer $server): void
    {
        $server->sites()->each(function (Site $site): void {
            $this->sync($site);
        });
    }

    public function disableSites(iterable $sites): void
    {
        foreach ($sites as $site) {
            if ($site instanceof Site) {
                $this->sync($site);
            }
        }
    }

    private function planeBaseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responsePayload
     */
    private function payloadContainsSecret(Site $site, array $responsePayload, array $requestPayload): bool
    {
        $secret = (string) $site->agent_secret_encrypted;
        $encoded = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            return false;
        }

        if ($secret !== '' && str_contains($encoded, $secret)) {
            return true;
        }

        $token = $site->mailServer?->api_token;
        if (is_string($token) && $token !== '' && str_contains($encoded, $token)) {
            return true;
        }

        foreach (['api_token', 'token', 'hostinger_token', 'password'] as $key) {
            if (array_key_exists($key, $requestPayload)) {
                return true;
            }
        }

        return false;
    }

    private function logFailure(Site $site, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Site mail configure failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }
}
