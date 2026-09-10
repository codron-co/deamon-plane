<?php

use App\Http\Controllers\Ops\CoolifyConnectionController;
use App\Http\Controllers\Ops\CoolifyInventoryController;
use Illuminate\Support\Facades\Route;

Route::get('/coolify', [CoolifyConnectionController::class, 'index'])->name('ops.coolify.index');
Route::get('/coolify/create', [CoolifyConnectionController::class, 'create'])->name('ops.coolify.create');
Route::post('/coolify', [CoolifyConnectionController::class, 'store'])->name('ops.coolify.store');
Route::get('/coolify/{connection}', [CoolifyConnectionController::class, 'show'])->name('ops.coolify.show');
Route::get('/coolify/{connection}/options', [CoolifyConnectionController::class, 'options'])->name('ops.coolify.options');
Route::put('/coolify/{connection}', [CoolifyConnectionController::class, 'update'])->name('ops.coolify.update');
Route::delete('/coolify/{connection}', [CoolifyConnectionController::class, 'destroy'])->name('ops.coolify.destroy');
Route::post('/coolify/{connection}/test', [CoolifyConnectionController::class, 'test'])->name('ops.coolify.test');
Route::get('/coolify/{connection}/sync', [CoolifyConnectionController::class, 'redirectGetSync'])->name('ops.coolify.sync.get');
Route::post('/coolify/{connection}/sync', [CoolifyConnectionController::class, 'sync'])->name('ops.coolify.sync');
Route::post('/coolify/{connection}/default', [CoolifyConnectionController::class, 'makeDefault'])->name('ops.coolify.default');
Route::get('/coolify/{connection}/servers/{server}', [CoolifyInventoryController::class, 'showServer'])->name('ops.coolify.servers.show');
Route::get('/coolify/{connection}/projects/{project}', [CoolifyInventoryController::class, 'showProject'])->name('ops.coolify.projects.show');
Route::get('/coolify/{connection}/environments/{environment}', [CoolifyInventoryController::class, 'showEnvironment'])->name('ops.coolify.environments.show');
Route::get('/coolify/{connection}/git-sources/{source}', [CoolifyInventoryController::class, 'showGitSource'])->name('ops.coolify.git-sources.show');
Route::post('/coolify/{connection}/servers/{server}/toggle', [CoolifyConnectionController::class, 'toggleServer'])->name('ops.coolify.servers.toggle');
Route::post('/coolify/{connection}/projects/{project}/toggle', [CoolifyConnectionController::class, 'toggleProject'])->name('ops.coolify.projects.toggle');
Route::post('/coolify/{connection}/environments/{environment}/toggle', [CoolifyConnectionController::class, 'toggleEnvironment'])->name('ops.coolify.environments.toggle');
Route::post('/coolify/{connection}/git-sources/{source}/toggle', [CoolifyConnectionController::class, 'toggleGitSource'])->name('ops.coolify.git-sources.toggle');
