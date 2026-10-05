<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\InvoiceStatusController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Support\Facades\Route;

Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:tokens')->name('tokens.store');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
    Route::apiResource('customers', CustomerController::class)->only(['index', 'store', 'show']);
    Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);

    Route::post('/invoices/{invoice}/send', [InvoiceStatusController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/void', [InvoiceStatusController::class, 'void'])->name('invoices.void');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
});
