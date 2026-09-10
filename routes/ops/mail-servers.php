<?php

use App\Http\Controllers\Ops\MailServerOpsController;
use Illuminate\Support\Facades\Route;

Route::get('/mail-servers', [MailServerOpsController::class, 'index'])->name('ops.mail-servers.index');
Route::get('/mail-servers/create', [MailServerOpsController::class, 'create'])->name('ops.mail-servers.create');
Route::post('/mail-servers', [MailServerOpsController::class, 'store'])->name('ops.mail-servers.store');
Route::get('/mail-servers/{mailServer}', [MailServerOpsController::class, 'show'])->name('ops.mail-servers.show');
Route::put('/mail-servers/{mailServer}', [MailServerOpsController::class, 'update'])->name('ops.mail-servers.update');
Route::delete('/mail-servers/{mailServer}', [MailServerOpsController::class, 'destroy'])->name('ops.mail-servers.destroy');
Route::post('/mail-servers/{mailServer}/test', [MailServerOpsController::class, 'test'])->name('ops.mail-servers.test');
Route::post('/mail-servers/{mailServer}/order', [MailServerOpsController::class, 'selectOrder'])->name('ops.mail-servers.order');
