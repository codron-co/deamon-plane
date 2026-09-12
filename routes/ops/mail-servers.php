<?php

use App\Http\Controllers\Ops\MailServerOpsController;
use App\Http\Controllers\Ops\PlatformMailSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/mail-servers', [MailServerOpsController::class, 'index'])->name('ops.mail-servers.index');
Route::get('/mail-servers/create', [MailServerOpsController::class, 'create'])->name('ops.mail-servers.create');
Route::post('/mail-servers', [MailServerOpsController::class, 'store'])->name('ops.mail-servers.store');
Route::get('/mail-servers/{mailServer}', [MailServerOpsController::class, 'show'])->name('ops.mail-servers.show');
Route::put('/mail-servers/{mailServer}', [MailServerOpsController::class, 'update'])->name('ops.mail-servers.update');
Route::delete('/mail-servers/{mailServer}', [MailServerOpsController::class, 'destroy'])->name('ops.mail-servers.destroy');
Route::post('/mail-servers/{mailServer}/test', [MailServerOpsController::class, 'test'])->name('ops.mail-servers.test');

Route::get('/platform-mail', [PlatformMailSettingsController::class, 'edit'])->name('ops.platform-mail.edit');
Route::put('/platform-mail', [PlatformMailSettingsController::class, 'update'])->name('ops.platform-mail.update');
Route::post('/platform-mail/push', [PlatformMailSettingsController::class, 'push'])->name('ops.platform-mail.push');
Route::post('/platform-mail/test', [PlatformMailSettingsController::class, 'test'])->name('ops.platform-mail.test');
