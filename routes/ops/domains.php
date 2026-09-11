<?php

use App\Http\Controllers\Ops\DomainController;
use Illuminate\Support\Facades\Route;

Route::get('/domains', [DomainController::class, 'index'])->name('ops.domains');
Route::post('/domains', [DomainController::class, 'store'])->name('ops.domains.store');
Route::patch('/domains/{domain}', [DomainController::class, 'update'])->name('ops.domains.update');
Route::post('/domains/{domain}/bind', [DomainController::class, 'bind'])->name('ops.domains.bind');
