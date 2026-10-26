<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\InstagramAccount;
use App\Models\InstagramDeletionRequest;
use App\Models\InstagramSetting;
use App\Models\Site;
use App\Services\Instagram\InstagramAgentClient;
use App\Services\Instagram\InstagramConnectLink;
use App\Services\Instagram\InstagramOAuthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Public OAuth broker for the CMS Instagram feed module. Customers' browsers reach these
 * routes (no Plane login), so the only trust anchors are the site's signed link and the
 * session-bound state.
 */
class InstagramOAuthController extends Controller
{
    private const SESSION_KEY = 'instagram_oauth';

    public function connect(Request $request, InstagramConnectLink $link, InstagramOAuthService $oauth): RedirectResponse|Response
    {
        if (! InstagramSetting::current()->isReady()) {
            return $this->errorPage('not_configured', 503);
        }

        try {
            $verified = $link->verify($request->query());
        } catch (RuntimeException $exception) {
            return $this->errorPage($exception->getMessage(), 403);
        }

        $state = bin2hex(random_bytes(20));
        $request->session()->put(self::SESSION_KEY, [
            'hash' => hash_hmac('sha256', $state, (string) config('app.key')),
            'site_id' => (string) $verified['site']->id,
            'return' => $verified['return'],
            'expires_at' => now()->addMinutes(20)->timestamp,
        ]);

        return redirect()->away($oauth->authorizeUrl($state));
    }

    public function callback(Request $request, InstagramOAuthService $oauth, InstagramAgentClient $agent): RedirectResponse|Response
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $given = (string) $request->query('state', '');

        if (! is_array($stored)
            || (int) ($stored['expires_at'] ?? 0) < time()
            || $given === ''
            || ! hash_equals((string) ($stored['hash'] ?? ''), hash_hmac('sha256', $given, (string) config('app.key')))) {
            return $this->errorPage('invalid_state', 400);
        }

        $return = (string) $stored['return'];
        $site = Site::query()->find((string) $stored['site_id']);
        if (! $site instanceof Site) {
            return $this->errorPage('invalid_state', 400);
        }

        if ($request->filled('error')) {
            return redirect()->away($this->returnUrl($return, ['instagram' => 'denied']));
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            return redirect()->away($this->returnUrl($return, ['instagram' => 'error', 'reason' => 'Instagram yetkilendirme kodu gelmedi.']));
        }

        try {
            $token = $oauth->exchangeCode($code);
        } catch (RuntimeException $exception) {
            Log::warning('Instagram code exchange failed', ['site_id' => $site->id, 'reason' => $exception->getMessage()]);

            return redirect()->away($this->returnUrl($return, ['instagram' => 'error', 'reason' => 'Instagram oturumu doğrulanamadı, lütfen tekrar deneyin.']));
        }

        $result = $agent->pushToken($site, $token['access_token'], $token['expires_in']);

        AuditLog::query()->create([
            'actor_user_id' => null,
            'action' => $result['ok'] ? 'instagram.connected' : 'instagram.connect_failed',
            'subject_type' => Site::class,
            'subject_id' => $site->id,
            'before' => null,
            'after' => ['username' => $result['username'] ?? null, 'error' => $result['error'] ?? null],
            'ip' => $request->ip(),
        ]);

        if ($result['ok'] && $token['user_id'] !== '') {
            InstagramAccount::recordConnection($site, $token['user_id'], $result['username'] ?? null);
        }

        if (! $result['ok']) {
            $reason = match ($result['error'] ?? '') {
                'module_disabled' => 'Instagram Akışı modülü bu sitede etkin değil.',
                'token_rejected' => 'Hesap bağlanamadı. Instagram hesabının «Profesyonel» (İşletme veya İçerik Üreticisi) olduğundan emin olun.',
                default => 'Site şu an yanıt vermiyor, birkaç dakika sonra tekrar deneyin.',
            };

            return redirect()->away($this->returnUrl($return, ['instagram' => 'error', 'reason' => $reason]));
        }

        return redirect()->away($this->returnUrl($return, ['instagram' => 'connected']));
    }

    public function dataDeletion(): View
    {
        return view('instagram.data-deletion');
    }

    public function deletionStatus(string $code): View
    {
        $deletion = InstagramDeletionRequest::query()->where('confirmation_code', $code)->firstOrFail();

        return view('instagram.deletion-status', ['deletion' => $deletion]);
    }

    /**
     * Privacy policy and terms the Meta app links to (App settings → Basic); App Review needs both.
     */
    public function privacy(): View
    {
        return view('instagram.legal', ['page' => 'privacy']);
    }

    public function terms(): View
    {
        return view('instagram.legal', ['page' => 'terms']);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function returnUrl(string $return, array $params): string
    {
        $base = strtok($return, '#') ?: $return;

        return $base.(str_contains($base, '?') ? '&' : '?').http_build_query($params);
    }

    private function errorPage(string $reason, int $status): Response
    {
        return response()->view('instagram.error', ['reason' => $reason], $status);
    }
}
