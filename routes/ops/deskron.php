<?php

use App\Http\Controllers\Ops\DeskronSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/deskron', [DeskronSettingsController::class, 'edit'])->name('ops.deskron.edit');
Route::put('/deskron', [DeskronSettingsController::class, 'update'])->name('ops.deskron.update');
