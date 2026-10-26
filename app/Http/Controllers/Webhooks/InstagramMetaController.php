<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInstagramDeletionRequestJob;
use App\Models\InstagramDeletionRequest;
use App\Models\InstagramSetting;
use App\Services\Instagram\InstagramSignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meta → Plane callbacks set in the Instagram business login settings (Deauthorize callback URL,
 * Data deletion request URL). Both carry a `signed_request` signed with the Instagram app secret.
 */
class InstagramMetaController extends Controller
{
    public function deauthorize(Request $request): JsonResponse
    {
        $userId = $this->verifiedUserId($request);
        if ($userId === null) {
            return response()->json(['error' => 'invalid_signed_request'], 400);
        }

        $deletion = InstagramDeletionRequest::open(InstagramDeletionRequest::TYPE_DEAUTHORIZE, $userId);
        ProcessInstagramDeletionRequestJob::dispatch($deletion->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Meta expects `url` (a page the person can check) and `confirmation_code`.
     */
    public function dataDeletion(Request $request): JsonResponse
    {
        $userId = $this->verifiedUserId($request);
        if ($userId === null) {
            return response()->json(['error' => 'invalid_signed_request'], 400);
        }

        $deletion = InstagramDeletionRequest::open(InstagramDeletionRequest::TYPE_DELETION, $userId);
        ProcessInstagramDeletionRequestJob::dispatch($deletion->id);

        return response()->json([
            'url' => $deletion->statusUrl(),
            'confirmation_code' => $deletion->confirmation_code,
        ]);
    }

    private function verifiedUserId(Request $request): ?string
    {
        $settings = InstagramSetting::current();
        $payload = InstagramSignedRequest::parse(
            (string) $request->input('signed_request', ''),
            $settings->isReady() ? (string) $settings->app_secret : '',
        );

        $userId = is_array($payload) ? (string) ($payload['user_id'] ?? '') : '';

        return preg_match('/^\d{1,64}$/', $userId) === 1 ? $userId : null;
    }
}
