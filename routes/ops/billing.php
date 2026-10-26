<?php

use App\Http\Controllers\Ops\MailBillingOpsController;
use Illuminate\Support\Facades\Route;

// CoMail billing: orders and payment settings (docs/modules/comail.md).
Route::get('/billing/orders', [MailBillingOpsController::class, 'index'])->name('ops.billing.orders.index');
Route::get('/billing/orders/{mailOrder}', [MailBillingOpsController::class, 'show'])->name('ops.billing.orders.show');
Route::post('/billing/orders/{mailOrder}/confirm', [MailBillingOpsController::class, 'confirm'])->name('ops.billing.orders.confirm');
Route::post('/billing/orders/{mailOrder}/cancel', [MailBillingOpsController::class, 'cancel'])->name('ops.billing.orders.cancel');
Route::post('/billing/orders/{mailOrder}/fail', [MailBillingOpsController::class, 'fail'])->name('ops.billing.orders.fail');
Route::get('/billing/settings', [MailBillingOpsController::class, 'editSettings'])->name('ops.billing.settings.edit');
Route::put('/billing/settings', [MailBillingOpsController::class, 'updateSettings'])->name('ops.billing.settings.update');
