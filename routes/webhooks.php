<?php

use App\Http\Controllers\Webhooks\CoolifyWebhookController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Controllers\Webhooks\InstagramMetaController;
use App\Http\Middleware\VerifyCoolifyWebhook;
use App\Http\Middleware\VerifyGitHubWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/coolify', CoolifyWebhookController::class)
    ->middleware(['throttle:60,1', VerifyCoolifyWebhook::class])
    ->name('coolify');

Route::post('/github', GitHubWebhookController::class)
    ->middleware(['throttle:60,1', VerifyGitHubWebhook::class])
    ->name('github');

// Meta (Instagram business login settings): signed_request, verified in the controller.
Route::post('/instagram/deauthorize', [InstagramMetaController::class, 'deauthorize'])
    ->middleware('throttle:60,1')
    ->name('instagram.deauthorize');

Route::post('/instagram/data-deletion', [InstagramMetaController::class, 'dataDeletion'])
    ->middleware('throttle:60,1')
    ->name('instagram.data-deletion');
