<?php

namespace App\Services\Agent;

use App\Models\Site;
use App\Support\ControlPlaneAgentSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SiteAgentClient
{
    public function health(Site $site): AgentHealthResult
    {
        if (! $site->hasAgentSecret()) {
            return AgentHealthResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return AgentHealthResult::unknown(
                AgentHealthReason::NoBaseUrl,
                'Site has no agent base URL or primary domain.',
            );
        }

        $secret = (string) $site->agent_secret_encrypted;
        $path = ControlPlaneAgentContract::healthPath();
        $url = $baseUrl.$path;
        $signed = ControlPlaneAgentSignature::headers($secret, '');
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->logFailure($site, AgentHealthReason::Timeout);

            return AgentHealthResult::failure(
                AgentHealthReason::Timeout,
                'Agent health timed out.',
            );
        } catch (Throwable) {
            $this->logFailure($site, AgentHealthReason::HttpError);

            return AgentHealthResult::failure(
                AgentHealthReason::HttpError,
                'Agent health request failed.',
            );
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->logFailure($site, AgentHealthReason::BadSignature, $response->status());

            return AgentHealthResult::failure(
                AgentHealthReason::BadSignature,
                'Agent rejected the request signature.',
                $response->status(),
            );
        }

        if ($response->failed()) {
            $this->logFailure($site, AgentHealthReason::HttpError, $response->status());

            return AgentHealthResult::failure(
                AgentHealthReason::HttpError,
                'Agent health returned HTTP '.$response->status().'.',
                $response->status(),
            );
        }

        $json = $response->json();
        if (! is_array($json)) {
            $this->logFailure($site, AgentHealthReason::HttpError, $response->status());

            return AgentHealthResult::failure(
                AgentHealthReason::HttpError,
                'Agent health returned a non-JSON body.',
                $response->status(),
            );
        }

        if ($this->payloadContainsSecret($site, $json)) {
            $this->logFailure($site, AgentHealthReason::HttpError, $response->status());

            return AgentHealthResult::failure(
                AgentHealthReason::HttpError,
                'Agent health payload was discarded.',
                $response->status(),
            );
        }

        return AgentHealthResult::fromCmsPayload($json, $response->status());
    }

    public function listThemes(Site $site): ThemeAgentResult
    {
        return $this->getTheme($site, ControlPlaneAgentContract::themeListPath());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function installTheme(Site $site, array $payload): ThemeAgentResult
    {
        return $this->postTheme($site, ControlPlaneAgentContract::themeInstallPath(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateTheme(Site $site, array $payload): ThemeAgentResult
    {
        return $this->postTheme($site, ControlPlaneAgentContract::themeUpdatePath(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function activateTheme(Site $site, array $payload): ThemeAgentResult
    {
        return $this->postTheme($site, ControlPlaneAgentContract::themeActivatePath(), $payload);
    }

    /**
     * Installs the CMS-side data package (sync.json + data/) from the control-plane
     * clone. CMS < 1.2.14 has no such route and answers 404.
     *
     * @param  array<string, mixed>  $payload
     */
    public function installThemeData(Site $site, array $payload): ThemeAgentResult
    {
        return $this->postTheme($site, ControlPlaneAgentContract::themeDataInstallPath(), $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncTheme(Site $site, array $payload): ThemeAgentResult
    {
        return $this->postTheme($site, ControlPlaneAgentContract::themeSyncPath(), $payload);
    }

    public function listAdmins(Site $site): AdminAgentResult
    {
        return $this->requestAdmins($site, 'GET', ControlPlaneAgentContract::adminsPath());
    }

    /**
     * @param  array{name: string, email: string, password: string}  $payload
     */
    public function createAdmin(Site $site, array $payload): AdminAgentResult
    {
        return $this->requestAdmins($site, 'POST', ControlPlaneAgentContract::adminsPath(), $payload);
    }

    /**
     * @param  array{password: string}  $payload
     */
    public function resetAdminPassword(Site $site, int $adminId, array $payload): AdminAgentResult
    {
        return $this->requestAdmins($site, 'POST', ControlPlaneAgentContract::adminPasswordPath($adminId), $payload);
    }

    /**
     * @param  array{is_active: bool}  $payload
     */
    public function updateAdmin(Site $site, int $adminId, array $payload): AdminAgentResult
    {
        return $this->requestAdmins($site, 'PATCH', ControlPlaneAgentContract::adminPath($adminId), $payload);
    }

    public function deleteAdmin(Site $site, int $adminId): AdminAgentResult
    {
        return $this->requestAdmins($site, 'DELETE', ControlPlaneAgentContract::adminPath($adminId));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function requestAdmins(Site $site, string $method, string $path, ?array $payload = null): AdminAgentResult
    {
        if (! $site->hasAgentSecret()) {
            return AdminAgentResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return AdminAgentResult::failure('Site has no agent base URL or primary domain.');
        }

        $method = strtoupper($method);
        $body = '';
        if ($payload !== null) {
            $body = ControlPlaneAgentContract::encodeJson($payload);
            if ($body === '') {
                return AdminAgentResult::failure('Admin agent payload could not be encoded.');
            }
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.$path;

        try {
            $pending = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers']);

            $response = match ($method) {
                'GET' => $pending->get($url),
                'POST' => $pending->withBody($body, 'application/json')->post($url),
                'PATCH' => $pending->withBody($body, 'application/json')->patch($url),
                'DELETE' => $body === ''
                    ? $pending->delete($url)
                    : $pending->withBody($body, 'application/json')->delete($url),
                default => throw new \InvalidArgumentException('Unsupported admin agent method.'),
            };
        } catch (ConnectionException) {
            $this->logAdminFailure($site, $path, 'timeout');

            return AdminAgentResult::failure('Admin agent timed out.');
        } catch (Throwable) {
            $this->logAdminFailure($site, $path, 'http_error');

            return AdminAgentResult::failure('Admin agent request failed.');
        }

        if ($response->failed()) {
            $json = $response->json();
            $result = AdminAgentResult::fromCmsError(is_array($json) ? $json : null, $response->status());
            $this->logAdminFailure($site, $path, $result->errorCode ?? 'http_error', $response->status());

            return $result;
        }

        $json = $response->json();
        if ($json === null || $json === []) {
            return AdminAgentResult::success(httpStatus: $response->status());
        }

        if (! is_array($json)) {
            $this->logAdminFailure($site, $path, 'http_error', $response->status());

            return AdminAgentResult::failure('Admin agent returned a non-JSON body.', $response->status());
        }

        if ($this->payloadContainsSecret($site, $json)) {
            $this->logAdminFailure($site, $path, 'secret_echo', $response->status());

            return AdminAgentResult::failure('Admin agent payload was discarded.', $response->status());
        }

        if (is_array($payload) && $this->adminPayloadEchoesPassword($payload, $json)) {
            $this->logAdminFailure($site, $path, 'secret_echo', $response->status());

            return AdminAgentResult::failure('Admin agent payload was discarded.', $response->status());
        }

        return AdminAgentResult::fromCmsPayload($json, $response->status());
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responsePayload
     */
    private function adminPayloadEchoesPassword(array $requestPayload, array $responsePayload): bool
    {
        $password = $requestPayload['password'] ?? null;
        if (! is_string($password) || $password === '') {
            return false;
        }

        $encoded = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && str_contains($encoded, $password);
    }

    private function logAdminFailure(Site $site, string $path, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Site admin agent call failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'path' => $path,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }

    private function getTheme(Site $site, string $path): ThemeAgentResult
    {
        if (! $site->hasAgentSecret()) {
            return ThemeAgentResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return ThemeAgentResult::failure('Site has no agent base URL or primary domain.');
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, '');
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.$path;

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->get($url);
        } catch (ConnectionException) {
            $this->logThemeFailure($site, $path, 'timeout');

            return ThemeAgentResult::failure('Theme agent timed out.');
        } catch (Throwable) {
            $this->logThemeFailure($site, $path, 'http_error');

            return ThemeAgentResult::failure('Theme agent request failed.');
        }

        if ($response->failed()) {
            $json = $response->json();
            $result = ThemeAgentResult::fromCmsError(is_array($json) ? $json : null, $response->status());
            $this->logThemeFailure($site, $path, $result->errorCode ?? 'http_error', $response->status());

            return $result;
        }

        $json = $response->json();
        if (! is_array($json)) {
            $this->logThemeFailure($site, $path, 'http_error', $response->status());

            return ThemeAgentResult::failure('Theme agent returned a non-JSON body.', $response->status());
        }

        if ($this->payloadContainsSecret($site, $json)) {
            $this->logThemeFailure($site, $path, 'secret_echo', $response->status());

            return ThemeAgentResult::failure('Theme agent payload was discarded.', $response->status());
        }

        return ThemeAgentResult::fromCmsPayload($json, $response->status());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postTheme(Site $site, string $path, array $payload): ThemeAgentResult
    {
        if (! $site->hasAgentSecret()) {
            return ThemeAgentResult::needsSecret();
        }

        $baseUrl = $site->resolvedAgentBaseUrl();
        if ($baseUrl === null) {
            return ThemeAgentResult::failure('Site has no agent base URL or primary domain.');
        }

        $body = ControlPlaneAgentContract::encodeJson($payload);
        if ($body === '') {
            return ThemeAgentResult::failure('Theme agent payload could not be encoded.');
        }

        $secret = (string) $site->agent_secret_encrypted;
        $signed = ControlPlaneAgentSignature::headers($secret, $body);
        $timeout = max(1, (int) config('ops.agent.timeout_seconds', 10));
        $url = $baseUrl.$path;

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withHeaders($signed['headers'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (ConnectionException) {
            $this->logThemeFailure($site, $path, 'timeout');

            return ThemeAgentResult::failure('Theme agent timed out.');
        } catch (Throwable) {
            $this->logThemeFailure($site, $path, 'http_error');

            return ThemeAgentResult::failure('Theme agent request failed.');
        }

        if ($response->failed()) {
            $json = $response->json();
            $result = ThemeAgentResult::fromCmsError(is_array($json) ? $json : null, $response->status());
            $this->logThemeFailure($site, $path, $result->errorCode ?? 'http_error', $response->status());

            return $result;
        }

        $json = $response->json();
        if ($json === null || $json === []) {
            return ThemeAgentResult::success(null, isset($payload['theme_id']) ? (string) $payload['theme_id'] : null, $response->status());
        }

        if (! is_array($json)) {
            $this->logThemeFailure($site, $path, 'http_error', $response->status());

            return ThemeAgentResult::failure('Theme agent returned a non-JSON body.', $response->status());
        }

        if ($this->payloadContainsSecret($site, $json) || $this->payloadContainsCloneToken($payload, $json)) {
            $this->logThemeFailure($site, $path, 'secret_echo', $response->status());

            return ThemeAgentResult::failure('Theme agent payload was discarded.', $response->status());
        }

        return ThemeAgentResult::fromCmsPayload($json, $response->status());
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $responsePayload
     */
    private function payloadContainsCloneToken(array $requestPayload, array $responsePayload): bool
    {
        $token = $requestPayload['clone_token'] ?? null;
        if (! is_string($token) || $token === '') {
            return false;
        }

        $encoded = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        return is_string($encoded) && str_contains($encoded, $token);
    }

    private function logThemeFailure(Site $site, string $path, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Site theme agent call failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'path' => $path,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadContainsSecret(Site $site, array $payload): bool
    {
        $secret = (string) $site->agent_secret_encrypted;
        if ($secret === '') {
            return false;
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && str_contains($encoded, $secret)) {
            Log::warning('Site agent health payload contained a secret and was discarded', [
                'site_id' => $site->id,
                'site_slug' => $site->slug,
            ]);

            return true;
        }

        return false;
    }

    private function logFailure(Site $site, string $reason, ?int $httpStatus = null): void
    {
        Log::warning('Site agent health failed', [
            'site_id' => $site->id,
            'site_slug' => $site->slug,
            'reason' => $reason,
            'http_status' => $httpStatus,
        ]);
    }
}
