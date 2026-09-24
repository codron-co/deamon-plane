<?php

use App\Http\Controllers\Ops\FleetRolloutController;
use Illuminate\Support\Facades\Route;

Route::get('/rollouts', [FleetRolloutController::class, 'index'])->name('ops.rollouts');
Route::get('/rollouts/{rollout}', [FleetRolloutController::class, 'show'])->whereNumber('rollout')->name('ops.rollouts.show');
Route::post('/rollouts/{rollout}/halt', [FleetRolloutController::class, 'halt'])->whereNumber('rollout')->name('ops.rollouts.halt');
Route::post('/rollouts/{rollout}/resume', [FleetRolloutController::class, 'resume'])->whereNumber('rollout')->name('ops.rollouts.resume');
