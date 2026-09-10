<?php

use App\Http\Controllers\Ops\ThemeController;
use App\Http\Controllers\Ops\ThemeGitConnectionController;
use Illuminate\Support\Facades\Route;

Route::get('/themes', [ThemeController::class, 'index'])->name('ops.themes');
Route::post('/themes/sync', [ThemeController::class, 'sync'])->name('ops.themes.sync');

Route::post('/themes/git/connect', [ThemeGitConnectionController::class, 'connect'])->name('ops.themes.git.connect');
Route::get('/themes/git/callback', [ThemeGitConnectionController::class, 'callback'])->name('ops.themes.git.callback');
Route::get('/themes/git/installed', [ThemeGitConnectionController::class, 'installed'])->name('ops.themes.git.installed');
Route::post('/themes/git/connect-another', [ThemeGitConnectionController::class, 'connectAnother'])->name('ops.themes.git.connect-another');
Route::post('/themes/git/pat', [ThemeGitConnectionController::class, 'storePat'])->name('ops.themes.git.pat');
Route::get('/themes/git/{connection}', [ThemeGitConnectionController::class, 'show'])->name('ops.themes.git.show');
Route::patch('/themes/git/{connection}', [ThemeGitConnectionController::class, 'update'])->name('ops.themes.git.update');
Route::post('/themes/git/{connection}/sync-repos', [ThemeGitConnectionController::class, 'syncRepos'])->name('ops.themes.git.sync-repos');
Route::delete('/themes/git/{connection}', [ThemeGitConnectionController::class, 'destroy'])->name('ops.themes.git.destroy');

Route::get('/themes/{theme}', [ThemeController::class, 'show'])->name('ops.themes.show');
Route::put('/themes/{theme}', [ThemeController::class, 'update'])->name('ops.themes.update');
Route::post('/themes/{theme}/access', [ThemeController::class, 'grantAccess'])->name('ops.themes.access.store');
Route::delete('/themes/{theme}/access/{site}', [ThemeController::class, 'revokeAccess'])->name('ops.themes.access.destroy');
