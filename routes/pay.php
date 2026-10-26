<?php

use App\Http\Controllers\PayController;
use Illuminate\Support\Facades\Route;

// CoMail hosted checkout (customers' browsers and payment providers, no session).
Route::get('/pay/{order}/{token}', [PayController::class, 'show'])
    ->where(['token' => '[a-f0-9]{48}'])
    ->middleware('throttle:60,1')
    ->name('pay.show');
Route::get('/pay/{order}/{token}/result', [PayController::class, 'showResult'])
    ->where(['token' => '[a-f0-9]{48}'])
    ->middleware('throttle:60,1')
    ->name('pay.result');
Route::match(['GET', 'POST'], '/pay/{order}/{token}/done/{result}', [PayController::class, 'done'])
    ->where(['token' => '[a-f0-9]{48}', 'result' => 'ok|fail'])
    ->middleware('throttle:60,1')
    ->name('pay.done');

Route::post('/pay/callback/iyzico', [PayController::class, 'iyzicoCallback'])
    ->middleware('throttle:120,1')
    ->name('pay.callback.iyzico');
Route::post('/pay/callback/paytr', [PayController::class, 'paytrCallback'])
    ->middleware('throttle:240,1')
    ->name('pay.callback.paytr');
