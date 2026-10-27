<?php

namespace App\Services\Comail;

use App\Jobs\ConfigureSiteComailJob;
use App\Models\MailServer;
use App\Models\Site;
use App\Models\SiteComailDomain;
use App\Services\Agent\Concerns\RetriesThrottledAgentRequests;
use App\Services\Agent\ControlPlaneAgentContract;
use App\Services\Mail\SiteMailConfigureResult;
use App\Support\ControlPlaneAgentSignature;
use App\Support\PublicAppUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Plane → CMS `POST /internal/control/v1/comail/configure` (same HMAC as
 * mail/configure). Enabled while a CoMail server exists and the site has an
 * active CoMail domain. No key or token ever goes in the payload. The outcome
 * lands on `sites.comail_configured_at` / `comail_configure_failed_at`.
 */
class ComailSiteConfigurer
{
    use RetriesThrottledAgentRequests;

    public function queue(Site $site): void
    {
        ConfigureSiteComailJob::dispatch((string) $site->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Site $site): array
    {
        $server = MailServer::comail();
        // Own active domains, or (share group) those of a sibling site.
        $enabled = $server instanceof MailServer
            && SiteComailDomain::query()
                ->whereIn('site_id', [$site->getKey(), ...ComailDirectory::groupSiblings($site)->modelKeys()])
                ->where('status', SiteComailDomain::STATUS_ACTIVE)
                ->exists();

        return $enabled
            ? [
                'enabled' => true,
                'provider' => 'comail',
                'site_id' => (string) $site->id,
                'plane_base_url' => PublicAppUrl::forAgents(),
                'webmail_url' => $server->comailBaseUrl(),
            ]
            : [
                'enabled' => false,
                'provider' => null,
                'site_id' => (string) $site->id,
                'plane_base_url' => PublicAppUrl::forAgents(),
            ];
    }

    public function sync(Site $site, bool $retryConnection = false): SiteMailConfigureResult
    {
        if (! $site->hasAgentSecret()) {
            return SiteMailConfigureResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            $this->recordOutcome($site, 'no_base_url');

            return SiteMailConfigureResult::failure('Site has no agent base URL or primary domain.');
        }

        $payload = $this->payload($site);
        $body = ControlPlaneAgentContract::encodeJson($payload);
        if ($body === '') {
            $this->recordOutcome($site, 'encode_failed');

            return SiteMailConfigureResult::failure('CoMail configure payload could not be encoded.');
        }

        $secret = (string) $site->agent_secret_encrypted;
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.ControlPlaneAgentContract::comailConfigurePath();

        try {
            $response = $this->sendWithRetry(function () use ($secret, $timeout, $url, $body) {
                $signed = ControlPlaneAgentSignature::headers($secret, $body);

                return Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders($signed['headers'])
                    ->withBody($body, 'application/json')
                    ->post($url);
            }, $retryConnection);
        } catch (ConnectionException) {
            $this->logFailure($site, 'timeout');
            $this->recordOutcome($site, 'timeout');

            return SiteMailConfigureResult::failure('CoMail configure timed out.');
        } catch (Throwable) {
            $this->logFailure($site, 'http_error');
            $this->recordOutcome($site, 'http_error');

            return SiteMailConfigureResult::failure('CoMail configure request failed.');
        }

        if ($response->failed()) {
            $failure = $response->json();
            $cmsMessage = is_array($failure) && is_string($failure['message'] ?? null)
                ? mb_substr(trim($failure['message']), 0, 500)
                : null;
            if ($cmsMessage !== null && $this->containsSecret($site, $cmsMessage)) {
                $cmsMessage = null;
            }

            $this->logFailure($site, 'http_error', $response->status(), [
                'cms_error' => is_array($failure) && is_string($failure['error'] ?? null) ? $failure['error'] : null,
            ]);
            $this->recordOutcome($site, 'http_'.$response->status(), $cmsMessage);

            return SiteMailConfigureResult::failure(
                'CoMail configure returned HTTP '.$response->status().($cmsMessage !== null ? ': '.$cmsMessage : '.'),
                $response->status(),
            );
        }

        $encoded = json_encode($response->json(), JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $this->containsSecret($site, $encoded)) {
            $this->logFailure($site, 'secret_echo', $response->status());
            $this->recordOutcome($site, 'secret_echo');

            return SiteMailConfigureResult::failure('CoMail configure payload was discarded.', $response->status());
        }

        $this->recordOutcome($site, null);

        return SiteMailConfigureResult::ok((bool) $payload['enabled'], $response->status());
    }

    /**
     * Success clears the failure columns, so "failed" always means the last push failed.
     */
    private function recordOutcome(Site $site, ?string $error, ?string $message = null): void
    {
        $site->forceFill($error === null
            ? [
                'comail_configured_at' => now(),
                'comail_configure_failed_at' => null,
                'comail_configure_error' => null,
                'comail_configure_message' => null,
            ]
            : [
                'comail_configure_failed_at' => now(),
                'comail_configure_error' => mb_substr($error, 0, 64),
                'comail_configure_message' => $message,
            ]);
        $site->save();
    }

    private function containsSecret(Site $site, string $text): bool
    {
        $secret = (string) $site->agent_secret_encrypted;
        if ($secret !== '' && str_contains($text, $secret)) {
            return true;
        }

        $key = MailServer::comail()?->api_token;

        return is_string($key) && $key !== '' && str_contains($text, $key);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(Site $site, string $reason, ?int $httpStatus = null, array $context = []): void
    {
        Log::warning('Site CoMail configure failed', array_merge([
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ], array_filter($context, static fn (mixed $value): bool => $value !== null)));
    }
}
