<?php

use App\Http\Controllers\Ops\ComailMailboxOpsController;
use App\Http\Controllers\Ops\MailPackageOpsController;
use App\Http\Controllers\Ops\SiteComailOpsController;
use Illuminate\Support\Facades\Route;

// CoMail packages (docs/modules/comail.md).
Route::get('/mail-packages', [MailPackageOpsController::class, 'index'])->name('ops.mail-packages.index');
Route::get('/mail-packages/create', [MailPackageOpsController::class, 'create'])->name('ops.mail-packages.create');
Route::post('/mail-packages', [MailPackageOpsController::class, 'store'])->name('ops.mail-packages.store');
Route::get('/mail-packages/{mailPackage}', [MailPackageOpsController::class, 'edit'])->name('ops.mail-packages.edit');
Route::put('/mail-packages/{mailPackage}', [MailPackageOpsController::class, 'update'])->name('ops.mail-packages.update');
Route::delete('/mail-packages/{mailPackage}', [MailPackageOpsController::class, 'destroy'])->name('ops.mail-packages.destroy');

// Site detail → Infrastructure → CoMail card.
Route::post('/sites/{site}/comail/package', [SiteComailOpsController::class, 'package'])->name('ops.sites.comail.package');
Route::post('/sites/{site}/comail/domains', [SiteComailOpsController::class, 'addDomain'])->name('ops.sites.comail.domains.store');
Route::post('/sites/{site}/comail/domains/{comailDomain}/dns', [SiteComailOpsController::class, 'applyDns'])->name('ops.sites.comail.dns.apply');
Route::post('/sites/{site}/comail/domains/{comailDomain}/check', [SiteComailOpsController::class, 'checkDns'])->name('ops.sites.comail.dns.check');
Route::post('/sites/{site}/comail/domains/{comailDomain}/activate', [SiteComailOpsController::class, 'activate'])->name('ops.sites.comail.activate');
Route::post('/sites/{site}/comail/configure', [SiteComailOpsController::class, 'configure'])->name('ops.sites.comail.configure');

// CoMail mailboxes: the card's lazy list, the Super Admin search and the
// one-click webmail login (Super Admin only, checked in the controller).
Route::get('/comail/mailboxes', [ComailMailboxOpsController::class, 'search'])->name('ops.comail.mailboxes');
Route::get('/sites/{site}/comail/mailboxes/panel', [ComailMailboxOpsController::class, 'panel'])->name('ops.sites.comail.mailboxes.panel');
Route::post('/sites/{site}/comail/mailboxes/{address}/webmail', [ComailMailboxOpsController::class, 'webmail'])
    ->where('address', '[^/]+')
    ->middleware('throttle:comail-webmail-ops')
    ->name('ops.sites.comail.webmail');
