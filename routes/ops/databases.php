<?php

use App\Http\Controllers\Ops\DatabaseServerOpsController;
use App\Http\Controllers\Ops\SiteDatabaseController;
use Illuminate\Support\Facades\Route;

Route::get('/databases', [DatabaseServerOpsController::class, 'index'])->name('ops.databases.index');
Route::get('/databases/create', [DatabaseServerOpsController::class, 'create'])->name('ops.databases.create');
Route::post('/databases', [DatabaseServerOpsController::class, 'store'])->name('ops.databases.store');
Route::get('/databases/{databaseServer}', [DatabaseServerOpsController::class, 'show'])->name('ops.databases.show');
Route::put('/databases/{databaseServer}', [DatabaseServerOpsController::class, 'update'])->name('ops.databases.update');
Route::post('/databases/{databaseServer}/probe', [DatabaseServerOpsController::class, 'probe'])->name('ops.databases.probe');
Route::delete('/databases/{databaseServer}', [DatabaseServerOpsController::class, 'destroy'])->name('ops.databases.destroy');

Route::get('/sites/{site}/database', [SiteDatabaseController::class, 'show'])->name('ops.sites.database');
Route::post('/sites/{site}/database/query', [SiteDatabaseController::class, 'run'])
    ->middleware('throttle:60,1')
    ->name('ops.sites.database.query');
