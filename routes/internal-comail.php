<?php

use App\Http\Controllers\Internal\ComailSsoController;
use Illuminate\Support\Facades\Route;

// CoMail webmail server → Plane, prefix internal/comail/v1 (docs/modules/comail.md).
Route::post('/sso/redeem', [ComailSsoController::class, 'redeem'])
    ->middleware('throttle:comail-sso')
    ->name('internal.comail.sso.redeem');
