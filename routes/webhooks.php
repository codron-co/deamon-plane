<?php

use App\Http\Controllers\Webhooks\CoolifyWebhookController;
use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Middleware\VerifyCoolifyWebhook;
use App\Http\Middleware\VerifyGitHubWebhook;
use Illuminate\Support\Facades\Route;

Route::post('/coolify', CoolifyWebhookController::class)
    ->middleware(['throttle:60,1', VerifyCoolifyWebhook::class])
    ->name('coolify');

Route::post('/github', GitHubWebhookController::class)
    ->middleware(['throttle:60,1', VerifyGitHubWebhook::class])
    ->name('github');
