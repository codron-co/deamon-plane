<?php

use App\Http\Controllers\Internal\SiteComailController;
use App\Http\Controllers\Internal\SiteComailSecurityController;
use App\Http\Middleware\EnsureSiteMailSignature;
use Illuminate\Support\Facades\Route;

// CMS → Plane CoMail API, prefix internal/site/v1/comail (docs/modules/comail.md).
Route::middleware(['throttle:site-comail', EnsureSiteMailSignature::class])->group(function (): void {
    Route::get('/overview', [SiteComailController::class, 'overview']);

    // v3: mail security, spam filter and quarantine (CONTRACT-v3-security).
    Route::get('/security', [SiteComailSecurityController::class, 'show']);
    Route::post('/domains/{domain}/dmarc', [SiteComailSecurityController::class, 'dmarc']);
    Route::get('/mailboxes/{address}/spam', [SiteComailSecurityController::class, 'spam']);
    Route::put('/mailboxes/{address}/spam', [SiteComailSecurityController::class, 'updateSpam']);
    Route::post('/mailboxes/{address}/spam/{list}', [SiteComailSecurityController::class, 'storeSpamEntry'])
        ->whereIn('list', ['allow', 'block']);
    Route::delete('/mailboxes/{address}/spam/{list}/{id}', [SiteComailSecurityController::class, 'destroySpamEntry'])
        ->whereIn('list', ['allow', 'block']);
    Route::get('/quarantine', [SiteComailSecurityController::class, 'quarantine']);
    Route::post('/quarantine/{id}/release', [SiteComailSecurityController::class, 'releaseQuarantine']);
    Route::delete('/quarantine/{id}', [SiteComailSecurityController::class, 'destroyQuarantine']);

    Route::post('/domains', [SiteComailController::class, 'storeDomain']);
    Route::delete('/domains/{domain}', [SiteComailController::class, 'destroyDomain']);
    Route::get('/domains/{domain}/dns', [SiteComailController::class, 'dns']);
    Route::post('/domains/{domain}/dns', [SiteComailController::class, 'applyDns']);
    Route::post('/domains/{domain}/activate', [SiteComailController::class, 'activate']);

    Route::get('/mailboxes', [SiteComailController::class, 'mailboxes']);
    Route::post('/mailboxes', [SiteComailController::class, 'storeMailbox']);
    Route::patch('/mailboxes/{address}', [SiteComailController::class, 'updateMailbox']);
    Route::delete('/mailboxes/{address}', [SiteComailController::class, 'destroyMailbox']);
    Route::get('/mailboxes/{address}/autoreply', [SiteComailController::class, 'autoreply']);
    Route::put('/mailboxes/{address}/autoreply', [SiteComailController::class, 'updateAutoreply']);

    Route::get('/aliases', [SiteComailController::class, 'aliases']);
    Route::post('/aliases', [SiteComailController::class, 'storeAlias']);
    Route::patch('/aliases/{id}', [SiteComailController::class, 'updateAlias']);
    Route::delete('/aliases/{id}', [SiteComailController::class, 'destroyAlias']);

    Route::get('/migrations/hposta', [SiteComailController::class, 'hpostaMailboxes']);
    Route::post('/migrations/hposta', [SiteComailController::class, 'hpostaMigrate']);
    Route::get('/migrations', [SiteComailController::class, 'migrations']);
    Route::post('/migrations', [SiteComailController::class, 'storeMigration']);
    Route::delete('/migrations/{id}', [SiteComailController::class, 'destroyMigration']);
});
