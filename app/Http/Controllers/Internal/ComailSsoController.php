<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\MailServer;
use App\Services\Comail\ComailWebmailSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /internal/comail/v1/sso/redeem`: called by the CoMail webmail server
 * (not by sites). Body `{token}`; headers `X-CoMail-Timestamp` and
 * `X-CoMail-Signature` = hex HMAC-SHA256 of `{timestamp}.{rawBody}` with the
 * CoMail server's sso_secret (±60 s). Answers the one-time `{username, password}`
 * once; an unknown, used or expired token is 404.
 */
class ComailSsoController extends Controller
{
    public function redeem(Request $request, ComailWebmailSso $sso): JsonResponse
    {
        $server = MailServer::comail();
        $secret = $server instanceof MailServer ? (string) $server->sso_secret : '';
        $raw = (string) $request->getContent();

        if (! ComailWebmailSso::validSignature(
            $secret,
            (string) $request->header('X-CoMail-Timestamp', ''),
            (string) $request->header('X-CoMail-Signature', ''),
            $raw,
        )) {
            Log::notice('comail.sso_bad_signature', ['ip' => $request->ip()]);

            return $this->json(['ok' => false, 'error' => 'unauthorized', 'message' => 'İmza geçersiz.'], 401);
        }

        $body = json_decode($raw, true);
        $token = is_array($body) && is_string($body['token'] ?? null) ? strtolower(trim($body['token'])) : '';
        $credentials = $sso->redeem($token);
        if ($credentials === null) {
            return $this->json(['ok' => false, 'error' => 'not_found', 'message' => 'Giriş bağlantısının süresi dolmuş ya da kullanılmış.'], 404);
        }

        return $this->json($credentials, 200);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data, int $status): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }
}
