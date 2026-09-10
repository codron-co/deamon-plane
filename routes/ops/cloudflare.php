<?php

use App\Http\Controllers\Ops\CloudflareOpsController;
use App\Models\CloudflareSetting;
use Illuminate\Support\Facades\Route;

Route::bind('account', function (string $value): CloudflareSetting {
    return CloudflareSetting::query()->findOrFail($value);
});

Route::get('/cloudflare', [CloudflareOpsController::class, 'index'])->name('ops.cloudflare.index');
Route::get('/cloudflare/create', [CloudflareOpsController::class, 'create'])->name('ops.cloudflare.create');
Route::post('/cloudflare', [CloudflareOpsController::class, 'store'])->name('ops.cloudflare.store');

Route::get('/cloudflare/dns-defaults', [CloudflareOpsController::class, 'showDefaults'])->name('ops.cloudflare.defaults');
Route::post('/cloudflare/dns-defaults', [CloudflareOpsController::class, 'storeDefault'])->name('ops.cloudflare.defaults.store');
Route::post('/cloudflare/dns-defaults/reset', [CloudflareOpsController::class, 'resetDefaults'])->name('ops.cloudflare.defaults.reset');
Route::put('/cloudflare/dns-defaults/{record}', [CloudflareOpsController::class, 'updateDefault'])->name('ops.cloudflare.defaults.update');
Route::delete('/cloudflare/dns-defaults/{record}', [CloudflareOpsController::class, 'destroyDefault'])->name('ops.cloudflare.defaults.destroy');

Route::get('/cloudflare/{account}', [CloudflareOpsController::class, 'show'])->name('ops.cloudflare.show');
Route::put('/cloudflare/{account}', [CloudflareOpsController::class, 'update'])->name('ops.cloudflare.update');
Route::delete('/cloudflare/{account}', [CloudflareOpsController::class, 'destroy'])->name('ops.cloudflare.destroy');
Route::post('/cloudflare/{account}/test', [CloudflareOpsController::class, 'test'])->name('ops.cloudflare.test');
Route::post('/cloudflare/{account}/default', [CloudflareOpsController::class, 'makeDefault'])->name('ops.cloudflare.default');

Route::post('/cloudflare/{account}/zones', [CloudflareOpsController::class, 'storeZone'])->name('ops.cloudflare.zones.store');
Route::get('/cloudflare/{account}/zones/{zone}', [CloudflareOpsController::class, 'showZone'])->name('ops.cloudflare.zones.show');
Route::delete('/cloudflare/{account}/zones/{zone}', [CloudflareOpsController::class, 'destroyZone'])->name('ops.cloudflare.zones.destroy');
Route::post('/cloudflare/{account}/zones/{zone}/apply-defaults', [CloudflareOpsController::class, 'applyZoneDefaults'])->name('ops.cloudflare.zones.apply');
Route::post('/cloudflare/{account}/zones/{zone}/dns', [CloudflareOpsController::class, 'storeDns'])->name('ops.cloudflare.zones.dns.store');
Route::put('/cloudflare/{account}/zones/{zone}/dns/{record}', [CloudflareOpsController::class, 'updateDns'])->name('ops.cloudflare.zones.dns.update');
Route::delete('/cloudflare/{account}/zones/{zone}/dns/{record}', [CloudflareOpsController::class, 'destroyDns'])->name('ops.cloudflare.zones.dns.destroy');
