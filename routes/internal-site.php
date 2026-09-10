<?php

use App\Http\Controllers\Internal\SiteMailProxyController;
use App\Http\Middleware\EnsureSiteMailSignature;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:60,1', EnsureSiteMailSignature::class])->group(function (): void {
    Route::get('/mailboxes', [SiteMailProxyController::class, 'index']);
    Route::post('/mailboxes', [SiteMailProxyController::class, 'store']);
    Route::patch('/mailboxes/{mailboxId}/password', [SiteMailProxyController::class, 'password']);
    Route::delete('/mailboxes/{mailboxId}', [SiteMailProxyController::class, 'destroy']);
    Route::get('/mailbox-requests', [SiteMailProxyController::class, 'requests']);
    Route::post('/mailbox-requests', [SiteMailProxyController::class, 'storeRequest']);
});
